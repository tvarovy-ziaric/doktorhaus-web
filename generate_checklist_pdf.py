from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


OUT_PATH = Path("rychly_technicky_checklist.pdf")
FONT_REGULAR = "Arial"
FONT_BOLD = "Arial-Bold"
FONT_REGULAR_PATH = r"C:\Windows\Fonts\arial.ttf"
FONT_BOLD_PATH = r"C:\Windows\Fonts\arialbd.ttf"


SECTIONS = [
    (
        "Okolie a exteriér",
        [
            "voda pri dome po daždi",
            "sklon terénu smerom k stavbe",
            "stav sokla, fasády a odkvapov",
            "viditeľné praskliny alebo dodatočné opravy",
        ],
    ),
    (
        "Vlhkosť a pachy",
        [
            "zatuchnutý alebo plesnivý pach",
            "vlhké mapy v spodných miestnostiach",
            "čerstvé maľovanie len na niektorých miestach",
            "odvlhčovače alebo zakryté rohy miestností",
        ],
    ),
    (
        "Konštrukcie a rovinnosť",
        [
            "krivé podlahy alebo schody",
            "trhliny nad oknami a dverami",
            "zasekávanie dverí alebo okien",
            "viditeľné dodatočné podopieranie",
        ],
    ),
    (
        "Strecha a odvodnenie",
        [
            "stav krytiny a oplechovania",
            "žľaby, zvody a odvod vody od domu",
            "stopy po zatekaní v podkroví",
            "vek strechy a posledné opravy",
        ],
    ),
    (
        "Rozvody a technika",
        [
            "vek elektroinštalácie a ističov",
            "typ kúrenia a stav zdroja tepla",
            "rozvody vody, odpadu a viditeľné opravy",
            "miesta, kde bolo niečo prerábané",
        ],
    ),
    (
        "Dokumentácia",
        [
            "kolaudácia alebo stavebné povolenie",
            "projektová dokumentácia",
            "záznamy o rekonštrukciách",
            "revízne správy, ak existujú",
        ],
    ),
]

TIPS = [
    "Všímajte si opakujúce sa signály, nie jednu drobnosť.",
    "Foťte len nejasné miesta, ku ktorým sa chcete vrátiť.",
    "Pýtajte sa konkrétne na vek, opravy, revízie a príčiny.",
]


def register_fonts() -> None:
    pdfmetrics.registerFont(TTFont(FONT_REGULAR, FONT_REGULAR_PATH))
    pdfmetrics.registerFont(TTFont(FONT_BOLD, FONT_BOLD_PATH))


def draw_checkbox(c: canvas.Canvas, x: float, y: float, size: float = 4.2 * mm) -> None:
    c.setLineWidth(0.8)
    c.setStrokeColor(colors.HexColor("#7f8b88"))
    c.rect(x, y - size + 0.4 * mm, size, size, stroke=1, fill=0)


def draw_wrapped_text(
    c: canvas.Canvas,
    text: str,
    x: float,
    y: float,
    width: float,
    font_name: str,
    font_size: int,
    color: colors.Color,
    leading: float,
) -> float:
    c.setFont(font_name, font_size)
    c.setFillColor(color)
    words = text.split()
    lines = []
    current = ""
    for word in words:
        candidate = f"{current} {word}".strip()
        if c.stringWidth(candidate, font_name, font_size) <= width:
            current = candidate
        else:
            if current:
                lines.append(current)
            current = word
    if current:
        lines.append(current)

    for line in lines:
        c.drawString(x, y, line)
        y -= leading
    return y


def new_page(c: canvas.Canvas) -> float:
    c.showPage()
    width, height = A4
    c.setFillColor(colors.white)
    c.rect(0, 0, width, height, stroke=0, fill=1)
    return height - 20 * mm


def build_pdf() -> None:
    register_fonts()
    width, height = A4
    c = canvas.Canvas(str(OUT_PATH), pagesize=A4)
    c.setTitle("Rýchly technický checklist pred obhliadkou")
    c.setAuthor("DoktorHaus")

    margin_x = 18 * mm
    y = height - 22 * mm

    # Header
    c.setFillColor(colors.HexColor("#3a6f66"))
    c.roundRect(margin_x, y - 9 * mm, 55 * mm, 9 * mm, 3 * mm, stroke=0, fill=1)
    c.setFillColor(colors.white)
    c.setFont(FONT_BOLD, 11)
    c.drawString(margin_x + 4 * mm, y - 6.2 * mm, "DoktorHaus")

    y -= 17 * mm
    c.setFillColor(colors.HexColor("#1c1f23"))
    c.setFont(FONT_BOLD, 18)
    c.drawString(margin_x, y, "Rýchly technický checklist pred obhliadkou")
    y -= 7 * mm
    y = draw_wrapped_text(
        c,
        "Jednoduchá pomôcka pre kupujúceho. Nepíšte si verdikty, ale otázky a signály, ku ktorým sa chcete po obhliadke vrátiť.",
        margin_x,
        y,
        width - 2 * margin_x,
        FONT_REGULAR,
        10,
        colors.HexColor("#6f7680"),
        5.2 * mm,
    )

    # Tips panel
    y -= 2 * mm
    panel_height = 27 * mm
    c.setFillColor(colors.HexColor("#f1f3f2"))
    c.roundRect(margin_x, y - panel_height, width - 2 * margin_x, panel_height, 3 * mm, stroke=0, fill=1)
    c.setFillColor(colors.HexColor("#1c1f23"))
    c.setFont(FONT_BOLD, 11)
    c.drawString(margin_x + 4 * mm, y - 6 * mm, "Ako checklist používať")
    tip_y = y - 12 * mm
    for idx, tip in enumerate(TIPS, start=1):
        c.setFillColor(colors.HexColor("#3a6f66"))
        c.circle(margin_x + 6 * mm, tip_y + 0.8 * mm, 2.3 * mm, stroke=0, fill=1)
        c.setFillColor(colors.white)
        c.setFont(FONT_BOLD, 8)
        c.drawCentredString(margin_x + 6 * mm, tip_y - 0.2 * mm, str(idx))
        draw_wrapped_text(
            c,
            tip,
            margin_x + 11 * mm,
            tip_y,
            width - 2 * margin_x - 16 * mm,
            FONT_REGULAR,
            9,
            colors.HexColor("#4c545d"),
            4.6 * mm,
        )
        tip_y -= 6.3 * mm
    y -= panel_height + 9 * mm

    col_gap = 10 * mm
    col_width = (width - 2 * margin_x - col_gap) / 2
    left_x = margin_x
    right_x = margin_x + col_width + col_gap
    x = left_x

    for i, (title, items) in enumerate(SECTIONS):
        if y < 55 * mm:
            y = new_page(c)
            x = left_x

        c.setFillColor(colors.HexColor("#1c1f23"))
        c.setFont(FONT_BOLD, 11)
        c.drawString(x, y, title)
        y -= 6 * mm

        for item in items:
            if y < 30 * mm:
                y = new_page(c)
                x = left_x
                c.setFillColor(colors.HexColor("#1c1f23"))
                c.setFont(FONT_BOLD, 11)
                c.drawString(x, y, title + " (pokračovanie)")
                y -= 6 * mm
            draw_checkbox(c, x, y)
            y_after = draw_wrapped_text(
                c,
                item,
                x + 7 * mm,
                y,
                col_width - 9 * mm,
                FONT_REGULAR,
                9,
                colors.HexColor("#4c545d"),
                4.8 * mm,
            )
            y = y_after - 2.2 * mm

        if x == left_x:
            x = right_x
            y = height - 72 * mm
        else:
            x = left_x
            y -= 2 * mm

    # Notes section on last page
    if y < 45 * mm:
        y = new_page(c)
    c.setFillColor(colors.HexColor("#1c1f23"))
    c.setFont(FONT_BOLD, 11)
    c.drawString(margin_x, y, "Poznámky z obhliadky")
    y -= 6 * mm
    c.setStrokeColor(colors.HexColor("#d7dbde"))
    for _ in range(6):
        c.line(margin_x, y, width - margin_x, y)
        y -= 9 * mm

    y -= 2 * mm
    c.setFillColor(colors.HexColor("#6f7680"))
    c.setFont(FONT_REGULAR, 9)
    c.drawString(margin_x, y, "Ak sa opakuje viac signálov naraz, má zmysel pýtať sa konkrétnejšie alebo si nechať stav vysvetliť.")

    c.save()


if __name__ == "__main__":
    build_pdf()
