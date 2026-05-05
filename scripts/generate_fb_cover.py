from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "uploads" / "fb-cover-doktorhaus.png"

W, H = 1640, 624
BG_TOP = (250, 251, 249)
BG_BOTTOM = (237, 241, 238)
TEXT = (28, 31, 35)
MUTED = (102, 112, 119)
ACCENT = (54, 94, 87)
ACCENT_SOFT = (225, 233, 229)
CARD = (251, 252, 251)
BORDER = (217, 223, 219)


def font(name, size):
    base = Path("C:/Windows/Fonts")
    candidates = {
        "regular": ["segoeui.ttf", "arial.ttf"],
        "semibold": ["seguisb.ttf", "arialbd.ttf"],
        "bold": ["segoeuib.ttf", "arialbd.ttf"],
    }[name]
    for candidate in candidates:
        path = base / candidate
        if path.exists():
            return ImageFont.truetype(str(path), size)
    return ImageFont.load_default()


def lerp(a, b, t):
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


img = Image.new("RGB", (W, H), BG_TOP)
px = img.load()
for y in range(H):
    color = lerp(BG_TOP, BG_BOTTOM, y / (H - 1))
    for x in range(W):
        px[x, y] = color

draw = ImageDraw.Draw(img, "RGBA")

# Quiet technical grid, similar to the website background language.
for x in range(0, W, 48):
    draw.line([(x, 0), (x, H)], fill=ACCENT + (14,), width=1)
for y in range(0, H, 48):
    draw.line([(0, y), (W, y)], fill=ACCENT + (14,), width=1)

# Soft right-side field.
draw.polygon(
    [(1110, 0), (1640, 0), (1640, 624), (1350, 624), (1270, 470), (1196, 350), (1122, 242), (1082, 76)],
    fill=ACCENT_SOFT + (200,),
)

# Inspection card shadow.
shadow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
sdraw = ImageDraw.Draw(shadow)
sdraw.rectangle((1264, 118, 1588, 506), fill=(28, 31, 35, 24))
shadow = shadow.filter(ImageFilter.GaussianBlur(20))
img = Image.alpha_composite(img.convert("RGBA"), shadow)
draw = ImageDraw.Draw(img, "RGBA")

draw.rectangle((1264, 118, 1588, 506), fill=CARD + (255,), outline=BORDER + (255,), width=2)

# House + inspection detail.
draw.line([(1304, 288), (1432, 180), (1560, 288)], fill=ACCENT + (255,), width=10, joint="curve")
draw.line([(1332, 286), (1332, 442), (1532, 442), (1532, 286)], fill=ACCENT + (255,), width=10, joint="curve")
draw.line([(1362, 348), (1502, 348)], fill=BORDER + (255,), width=8)
draw.line([(1362, 388), (1468, 388)], fill=BORDER + (255,), width=8)
draw.line([(1362, 424), (1488, 424)], fill=BORDER + (255,), width=8)

# Diagnostic wave.
points = [(1214, 220), (1250, 220), (1288, 270), (1320, 306), (1358, 266), (1380, 298), (1398, 324), (1444, 344), (1476, 334), (1526, 318), (1594, 350)]
draw.line(points, fill=ACCENT + (58,), width=7, joint="curve")

# Simple brand mark inspired by the SVG: roof + pulse.
brand_x, brand_y = 116, 92
draw.line([(brand_x, brand_y + 64), (brand_x + 52, brand_y + 20), (brand_x + 104, brand_y + 64)], fill=TEXT + (255,), width=7, joint="curve")
draw.line([(brand_x + 18, brand_y + 68), (brand_x + 18, brand_y + 116), (brand_x + 88, brand_y + 116), (brand_x + 88, brand_y + 68)], fill=TEXT + (255,), width=7, joint="curve")
draw.line(
    [(brand_x - 34, brand_y + 88), (brand_x + 16, brand_y + 88), (brand_x + 34, brand_y + 62), (brand_x + 54, brand_y + 126), (brand_x + 72, brand_y + 84), (brand_x + 132, brand_y + 84)],
    fill=TEXT + (255,),
    width=7,
    joint="curve",
)

draw.text((brand_x + 140, brand_y + 45), "doktorhaus", fill=TEXT, font=font("bold", 66))

draw.text((116, 248), "Technická kontrola", fill=TEXT, font=font("semibold", 60))
draw.text((116, 326), "nehnuteľností", fill=TEXT, font=font("semibold", 60))
draw.rectangle((116, 406, 738, 408), fill=ACCENT + (72,))
draw.text((116, 444), "Odborný pohľad pred kúpou, predajom alebo rekonštrukciou.", fill=MUTED, font=font("regular", 29))

draw.rounded_rectangle((520, 512, 766, 566), radius=8, fill=ACCENT + (255,))
draw.text((548, 526), "doktorhaus.sk", fill=(255, 255, 255), font=font("semibold", 24))
draw.text((802, 526), "inšpekcia domov a bytov", fill=MUTED, font=font("regular", 24))

OUT.parent.mkdir(parents=True, exist_ok=True)
img.convert("RGB").save(OUT, quality=95)
print(OUT)
