"""Inventory Barang - versi Python (Tkinter).

Dua peran, satu data:
  * Admin   : kelola data barang, catat barang masuk dan keluar.
  * Pemilik : dashboard baca-saja (stok dan riwayat).

Jalankan : python inventory_barang.py
Akun     : admin / admin123  |  pemilik / pemilik123
Data disimpan di inventory_barang.json (satu folder dengan file ini).
"""
import json
import os
import tkinter as tk
from datetime import date, datetime
from tkinter import messagebox, ttk

DATA_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "inventory_barang.json")

ACCOUNTS = {
    "admin": {"pw": "admin123", "name": "Rina Set", "role": "admin"},
    "pemilik": {"pw": "pemilik123", "name": "Hendra Wijaya", "role": "owner"},
}

TEAL, NAVY, ORANGE, GREEN, MUTED = "#0F766E", "#1E3A5F", "#C2410C", "#15803D", "#6B7280"


def fmt(n):
    return f"{n:,}".replace(",", ".")


def seed():
    return {
        "items": [
            {"id": "i1", "name": "Kaos Polos Hitam", "unit": "pcs", "qty": 42, "min": 20},
            {"id": "i2", "name": "Sepatu Sneakers", "unit": "pasang", "qty": 5, "min": 8},
            {"id": "i3", "name": "Tas Ransel", "unit": "pcs", "qty": 30, "min": 10},
            {"id": "i4", "name": "Topi Baseball", "unit": "pcs", "qty": 18, "min": 10},
            {"id": "i5", "name": "Jaket Hoodie", "unit": "pcs", "qty": 12, "min": 6},
            {"id": "i6", "name": "Kemeja Flanel", "unit": "pcs", "qty": 0, "min": 5},
        ],
        "log": [
            {"date": "2026-10-12", "type": "in", "item": "i6", "qty": 6, "note": "Surat jalan SJ-0412", "by": "admin"},
            {"date": "2026-10-14", "type": "out", "item": "i1", "qty": 8, "note": "", "by": "admin"},
            {"date": "2026-10-15", "type": "in", "item": "i5", "qty": 10, "note": "", "by": "pemilik"},
            {"date": "2026-10-16", "type": "in", "item": "i3", "qty": 12, "note": "", "by": "admin"},
            {"date": "2026-10-16", "type": "out", "item": "i4", "qty": 3, "note": "", "by": "admin"},
            {"date": "2026-10-17", "type": "out", "item": "i2", "qty": 6, "note": "Pesanan online", "by": "admin"},
            {"date": "2026-10-17", "type": "in", "item": "i1", "qty": 24, "note": "", "by": "admin"},
        ],
    }


class Store:
    """Data barang + riwayat, disimpan sebagai JSON."""

    def __init__(self, path):
        self.path = path
        self.data = self._load()

    def _load(self):
        try:
            with open(self.path, encoding="utf-8") as f:
                return json.load(f)
        except (OSError, ValueError):
            return seed()

    def save(self):
        with open(self.path, "w", encoding="utf-8") as f:
            json.dump(self.data, f, ensure_ascii=False, indent=2)

    @property
    def items(self):
        return self.data["items"]

    @property
    def log(self):
        return self.data["log"]

    def item(self, item_id):
        return next((i for i in self.items if i["id"] == item_id), None)

    def month_totals(self):
        prefix = date.today().strftime("%Y-%m")
        totals = {"in": 0, "out": 0}
        for entry in self.log:
            if entry["date"].startswith(prefix):
                totals[entry["type"]] += entry["qty"]
        return totals["in"], totals["out"]

    def recent(self, kind=None, n=10):
        rows = [e for e in self.log if kind in (None, e["type"])]
        return sorted(rows, key=lambda e: e["date"], reverse=True)[:n]

    def add_item(self, name, unit, qty, minimum):
        self.items.append({
            "id": "i" + datetime.now().strftime("%y%m%d%H%M%S%f"),
            "name": name, "unit": unit or "pcs", "qty": qty, "min": minimum,
        })
        self.save()

    def delete_item(self, item_id):
        self.data["items"] = [i for i in self.items if i["id"] != item_id]
        self.save()

    def move(self, kind, item_id, qty, day, note, user):
        it = self.item(item_id)
        it["qty"] += qty if kind == "in" else -qty
        self.log.append({"date": day, "type": kind, "item": item_id, "qty": qty, "note": note, "by": user})
        self.save()


# ---------- Widget bantu ----------

def make_tree(parent, columns, height=8):
    """columns: [(judul, lebar, anchor)]. Mengembalikan Treeview dengan scrollbar."""
    frame = ttk.Frame(parent)
    tree = ttk.Treeview(frame, columns=[c[0] for c in columns], show="headings", height=height)
    for title, width, anchor in columns:
        tree.heading(title, text=title)
        tree.column(title, width=width, anchor=anchor)
    bar = ttk.Scrollbar(frame, orient="vertical", command=tree.yview)
    tree.configure(yscrollcommand=bar.set)
    tree.pack(side="left", fill="both", expand=True)
    bar.pack(side="right", fill="y")
    tree.tag_configure("low", foreground=ORANGE)
    tree.tag_configure("in", foreground=GREEN)
    tree.tag_configure("out", foreground=ORANGE)
    frame.tree = tree
    return frame


def kpi_row(parent, cards):
    row = ttk.Frame(parent)
    for i, (label, value) in enumerate(cards):
        box = ttk.LabelFrame(row, text=label, padding=(14, 6))
        box.grid(row=0, column=i, sticky="ew", padx=(0 if i == 0 else 8, 0))
        row.columnconfigure(i, weight=1)
        ttk.Label(box, text=value, font=("Segoe UI", 18, "bold")).pack(anchor="w")
    return row


def fill_stock(tree, store):
    tree.delete(*tree.get_children())
    for it in store.items:
        low = it["qty"] <= it["min"]
        tree.insert("", "end", iid=it["id"], tags=("low",) if low else (),
                    values=(it["name"], f"{fmt(it['qty'])} {it['unit']}", f"{it['min']} {it['unit']}",
                            "Menipis" if low else "Aman"))


def fill_history(tree, store, kind=None, n=10):
    tree.delete(*tree.get_children())
    for e in store.recent(kind, n):
        it = store.item(e["item"])
        name, unit = (it["name"], it["unit"]) if it else ("(barang dihapus)", "pcs")
        tree.insert("", "end", tags=(e["type"],),
                    values=(e["date"], name, "Masuk" if e["type"] == "in" else "Keluar",
                            f"{fmt(e['qty'])} {unit}", e["note"]))


STOCK_COLS = [("Barang", 260, "w"), ("Stok", 110, "e"), ("Minimum", 110, "e"), ("Status", 90, "center")]
HIST_COLS = [("Tanggal", 90, "w"), ("Barang", 220, "w"), ("Jenis", 70, "center"), ("Jumlah", 100, "e"), ("Catatan", 200, "w")]


# ---------- Layar login ----------

class LoginFrame(ttk.Frame):
    def __init__(self, app):
        super().__init__(app, padding=30)
        self.app = app
        self.role = tk.StringVar(value="admin")
        self.user, self.pw, self.err = tk.StringVar(), tk.StringVar(), tk.StringVar()

        card = ttk.Frame(self)
        card.place(relx=0.5, rely=0.45, anchor="center")
        ttk.Label(card, text="Inventory Barang", font=("Segoe UI", 20, "bold")).pack(pady=(0, 14))

        seg = ttk.Frame(card)
        seg.pack(fill="x")
        for text, value in (("Admin", "admin"), ("Pemilik", "owner")):
            ttk.Radiobutton(seg, text=text, value=value, variable=self.role,
                            command=self._role_changed).pack(side="left", expand=True)

        self.title = ttk.Label(card, font=("Segoe UI", 13, "bold"))
        self.title.pack(anchor="w", pady=(14, 0))
        self.sub = ttk.Label(card, foreground=MUTED, wraplength=300)
        self.sub.pack(anchor="w", pady=(0, 8))

        ttk.Label(card, text="Username").pack(anchor="w")
        self.user_entry = ttk.Entry(card, textvariable=self.user, width=36)
        self.user_entry.pack(fill="x", pady=(0, 8))
        ttk.Label(card, text="Password").pack(anchor="w")
        pw_entry = ttk.Entry(card, textvariable=self.pw, show="*", width=36)
        pw_entry.pack(fill="x")
        ttk.Label(card, textvariable=self.err, foreground=ORANGE).pack(anchor="w", pady=4)
        ttk.Button(card, text="Masuk", command=self.submit).pack(fill="x", ipady=3)
        ttk.Label(card, text="Akun contoh: admin / admin123  ·  pemilik / pemilik123",
                  foreground=MUTED).pack(pady=(12, 0))

        self.user_entry.bind("<Return>", lambda _e: self.submit())
        pw_entry.bind("<Return>", lambda _e: self.submit())
        self._role_changed()

    def _role_changed(self):
        owner = self.role.get() == "owner"
        self.title.config(text="Login Pemilik" if owner else "Login Admin")
        self.sub.config(text="Masuk untuk memantau stok dan riwayat barang." if owner
                        else "Masuk untuk mencatat barang masuk dan keluar.")
        self.err.set("")

    def reset(self):
        self.user.set("")
        self.pw.set("")
        self.err.set("")
        self.user_entry.focus_set()

    def submit(self):
        username = self.user.get().strip().lower()
        acc = ACCOUNTS.get(username)
        if not acc or acc["pw"] != self.pw.get():
            self.err.set("Username atau password salah.")
            self.pw.set("")
            return
        if (acc["role"] == "owner") != (self.role.get() == "owner"):
            self.err.set(f"Akun ini untuk login {'Pemilik' if acc['role'] == 'owner' else 'Admin'}.")
            return
        self.app.enter(username)


# ---------- Peran Pemilik (baca-saja) ----------

class OwnerFrame(ttk.Frame):
    def __init__(self, app):
        super().__init__(app, padding=16)
        self.app = app
        head = ttk.Frame(self)
        head.pack(fill="x")
        ttk.Label(head, text="Dashboard Pemilik", font=("Segoe UI", 18, "bold")).pack(side="left")
        ttk.Button(head, text="Keluar", command=app.logout).pack(side="right")
        ttk.Label(self, text="Pantau stok dan riwayat barang", foreground=MUTED).pack(anchor="w", pady=(0, 10))
        self.kpis = ttk.Frame(self)
        self.kpis.pack(fill="x")
        ttk.Label(self, text="Stok Barang Saat Ini", font=("Segoe UI", 11, "bold")).pack(anchor="w", pady=(14, 4))
        self.stock = make_tree(self, STOCK_COLS, 7)
        self.stock.pack(fill="both", expand=True)
        ttk.Label(self, text="Riwayat Terbaru", font=("Segoe UI", 11, "bold")).pack(anchor="w", pady=(14, 4))
        self.hist = make_tree(self, HIST_COLS, 6)
        self.hist.pack(fill="both", expand=True)

    def refresh(self):
        store = self.app.store
        in_q, out_q = store.month_totals()
        for w in self.kpis.winfo_children():
            w.destroy()
        kpi_row(self.kpis, [("Total Jenis Barang", fmt(len(store.items))),
                            ("Masuk Bulan Ini", f"{fmt(in_q)} pcs"),
                            ("Keluar Bulan Ini", f"{fmt(out_q)} pcs")]).pack(fill="x")
        fill_stock(self.stock.tree, store)
        fill_history(self.hist.tree, store, None, 5)


# ---------- Peran Admin ----------

class MovementTab(ttk.Frame):
    """Form + riwayat untuk barang masuk ('in') atau keluar ('out')."""

    def __init__(self, parent, app, kind):
        super().__init__(parent, padding=16)
        self.app, self.kind = app, kind
        self.label = "Barang Masuk" if kind == "in" else "Barang Keluar"
        self.item_var, self.qty_var = tk.StringVar(), tk.StringVar(value="1")
        self.date_var, self.note_var = tk.StringVar(value=date.today().isoformat()), tk.StringVar()
        self.msg = tk.StringVar()
        self.msg_label = ttk.Label(self, textvariable=self.msg)

        ttk.Label(self, text=self.label, font=("Segoe UI", 16, "bold")).grid(row=0, column=0, columnspan=2, sticky="w")
        ttk.Label(self, text="Catat barang yang masuk ke gudang" if kind == "in"
                  else "Catat barang yang keluar dari gudang", foreground=MUTED).grid(row=1, column=0, columnspan=2, sticky="w", pady=(0, 10))

        form = ttk.LabelFrame(self, text=f"Form {self.label.lower()}", padding=12)
        form.grid(row=2, column=0, columnspan=2, sticky="ew")
        form.columnconfigure(1, weight=1)
        self.combo = ttk.Combobox(form, textvariable=self.item_var, state="readonly")
        for r, (text, widget) in enumerate([
            ("Barang", self.combo),
            ("Jumlah", ttk.Spinbox(form, from_=1, to=1_000_000, textvariable=self.qty_var, width=10)),
            ("Tanggal (YYYY-MM-DD)", ttk.Entry(form, textvariable=self.date_var, width=14)),
            ("Catatan", ttk.Entry(form, textvariable=self.note_var)),
        ]):
            ttk.Label(form, text=text).grid(row=r, column=0, sticky="w", pady=3, padx=(0, 12))
            widget.grid(row=r, column=1, sticky="ew" if text in ("Barang", "Catatan") else "w", pady=3)
        ttk.Button(form, text=f"Simpan {self.label}", command=self.save).grid(row=4, column=1, sticky="e", pady=(8, 0))

        self.msg_label.grid(row=3, column=0, columnspan=2, sticky="w", pady=4)
        ttk.Label(self, text=f"Riwayat {'masuk' if kind == 'in' else 'keluar'}",
                  font=("Segoe UI", 11, "bold")).grid(row=4, column=0, sticky="w", pady=(8, 4))
        self.hist = make_tree(self, HIST_COLS, 6)
        self.hist.grid(row=5, column=0, columnspan=2, sticky="nsew")
        self.columnconfigure(0, weight=1)
        self.rowconfigure(5, weight=1)

    def _show(self, text, ok=False):
        self.msg_label.config(foreground=GREEN if ok else ORANGE)
        self.msg.set(text)

    def refresh(self):
        store = self.app.store
        self.options = {f"{i['name']} (stok {fmt(i['qty'])} {i['unit']})": i["id"] for i in store.items}
        self.combo["values"] = list(self.options)
        if self.item_var.get() not in self.options:
            self.item_var.set(next(iter(self.options), ""))
        fill_history(self.hist.tree, store, self.kind, 6)

    def save(self):
        store = self.app.store
        it = store.item(self.options.get(self.item_var.get()))
        if not it:
            return self._show("Pilih barang terlebih dahulu.")
        try:
            qty = int(self.qty_var.get())
            day = datetime.strptime(self.date_var.get().strip(), "%Y-%m-%d").date().isoformat()
        except ValueError:
            return self._show("Jumlah harus angka dan tanggal berformat YYYY-MM-DD.")
        if qty <= 0:
            return self._show("Jumlah harus lebih dari 0.")
        if self.kind == "out" and qty > it["qty"]:
            return self._show(f"Stok {it['name']} hanya {fmt(it['qty'])} {it['unit']}.")
        store.move(self.kind, it["id"], qty, day, self.note_var.get().strip(), self.app.me)
        self.note_var.set("")
        self.qty_var.set("1")
        self.app.refresh()
        self._show(f"Tersimpan: {'masuk' if self.kind == 'in' else 'keluar'} {fmt(qty)} {it['unit']} {it['name']}.", ok=True)


class AdminFrame(ttk.Frame):
    def __init__(self, app):
        super().__init__(app, padding=16)
        self.app = app
        head = ttk.Frame(self)
        head.pack(fill="x", pady=(0, 8))
        ttk.Label(head, text="Inventory Admin", font=("Segoe UI", 18, "bold")).pack(side="left")
        ttk.Button(head, text="Keluar", command=app.logout).pack(side="right")

        self.tabs = ttk.Notebook(self)
        self.tabs.pack(fill="both", expand=True)

        # Dashboard
        dash = ttk.Frame(self.tabs, padding=16)
        self.kpis = ttk.Frame(dash)
        self.kpis.pack(fill="x")
        ttk.Label(dash, text="Riwayat Terbaru", font=("Segoe UI", 11, "bold")).pack(anchor="w", pady=(14, 4))
        self.dash_hist = make_tree(dash, HIST_COLS, 8)
        self.dash_hist.pack(fill="both", expand=True)

        # Data barang
        items = ttk.Frame(self.tabs, padding=16)
        bar = ttk.Frame(items)
        bar.pack(fill="x", pady=(0, 8))
        ttk.Label(bar, text="Data Barang", font=("Segoe UI", 16, "bold")).pack(side="left")
        ttk.Button(bar, text="Hapus", command=self.delete_item).pack(side="right")
        ttk.Button(bar, text="+ Tambah Barang", command=self.add_item).pack(side="right", padx=6)
        self.stock = make_tree(items, STOCK_COLS, 12)
        self.stock.pack(fill="both", expand=True)

        self.move_in = MovementTab(self.tabs, app, "in")
        self.move_out = MovementTab(self.tabs, app, "out")
        for tab, title in ((dash, "Dashboard"), (items, "Data Barang"),
                           (self.move_in, "Barang Masuk"), (self.move_out, "Barang Keluar")):
            self.tabs.add(tab, text=title)

    def refresh(self):
        store = self.app.store
        in_q, out_q = store.month_totals()
        for w in self.kpis.winfo_children():
            w.destroy()
        kpi_row(self.kpis, [("Total Jenis Barang", fmt(len(store.items))),
                            ("Masuk Bulan Ini", f"{fmt(in_q)} pcs"),
                            ("Keluar Bulan Ini", f"{fmt(out_q)} pcs")]).pack(fill="x")
        fill_history(self.dash_hist.tree, store, None, 8)
        fill_stock(self.stock.tree, store)
        self.move_in.refresh()
        self.move_out.refresh()

    def delete_item(self):
        sel = self.stock.tree.selection()
        if not sel:
            return messagebox.showinfo("Hapus barang", "Pilih barang yang akan dihapus.")
        it = self.app.store.item(sel[0])
        if messagebox.askyesno("Hapus barang", f"Hapus '{it['name']}'?"):
            self.app.store.delete_item(it["id"])
            self.app.refresh()

    def add_item(self):
        dlg = tk.Toplevel(self)
        dlg.title("Tambah barang")
        dlg.transient(self.winfo_toplevel())
        dlg.resizable(False, False)
        body = ttk.Frame(dlg, padding=18)
        body.pack()
        ttk.Label(body, text="Stok awal bisa diisi di sini. Perubahan berikutnya dicatat\nlewat barang masuk dan keluar.",
                  foreground=MUTED).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 8))
        name, unit, qty, minimum = tk.StringVar(), tk.StringVar(value="pcs"), tk.StringVar(value="0"), tk.StringVar(value="5")
        entries = []
        for r, (text, var) in enumerate([("Nama barang", name), ("Satuan", unit), ("Stok awal", qty), ("Stok minimum", minimum)], start=1):
            ttk.Label(body, text=text).grid(row=r, column=0, sticky="w", pady=3, padx=(0, 12))
            e = ttk.Entry(body, textvariable=var, width=30)
            e.grid(row=r, column=1, pady=3)
            entries.append(e)
        err = tk.StringVar()
        ttk.Label(body, textvariable=err, foreground=ORANGE).grid(row=5, column=0, columnspan=2, sticky="w")

        def submit():
            if not name.get().strip():
                return err.set("Nama barang wajib diisi.")
            try:
                q, m = int(qty.get() or 0), int(minimum.get() or 0)
            except ValueError:
                return err.set("Stok harus berupa angka.")
            if q < 0 or m < 0:
                return err.set("Stok tidak boleh negatif.")
            self.app.store.add_item(name.get().strip(), unit.get().strip(), q, m)
            dlg.destroy()
            self.app.refresh()

        btns = ttk.Frame(body)
        btns.grid(row=6, column=0, columnspan=2, sticky="e", pady=(10, 0))
        ttk.Button(btns, text="Batal", command=dlg.destroy).pack(side="left", padx=6)
        ttk.Button(btns, text="Simpan", command=submit).pack(side="left")
        dlg.bind("<Return>", lambda _e: submit())
        entries[0].focus_set()
        dlg.grab_set()


# ---------- Aplikasi ----------

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Inventory Barang")
        self.geometry("880x640")
        self.minsize(720, 520)
        self.store = Store(DATA_FILE)
        self.me = None
        self.login = LoginFrame(self)
        self.admin = AdminFrame(self)
        self.owner = OwnerFrame(self)
        self.show_login()

    def _show(self, frame):
        for f in (self.login, self.admin, self.owner):
            f.pack_forget()
        frame.pack(fill="both", expand=True)

    def show_login(self):
        self.me = None
        self._show(self.login)
        self.login.reset()

    def enter(self, username):
        self.me = username
        self._show(self.owner if ACCOUNTS[username]["role"] == "owner" else self.admin)
        self.refresh()

    def logout(self):
        self.show_login()

    def refresh(self):
        if self.me:
            (self.owner if ACCOUNTS[self.me]["role"] == "owner" else self.admin).refresh()


if __name__ == "__main__":
    App().mainloop()
