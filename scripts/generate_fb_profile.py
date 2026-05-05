from pathlib import Path
from PIL import Image, ImageDraw, ImageFilter

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "uploads" / "fb-profile-doktorhaus.png"

W = H = 1024
BG_TOP = (250, 251, 249)
BG_BOTTOM = (237, 241, 238)
TEXT = (28, 31, 35)
ACCENT = (54, 94, 87)
ACCENT_SOFT = (225, 233, 229)
BORDER = (217, 223, 219)


def lerp(a, b, t):
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


img = Image.new("RGB", (W, H), BG_TOP)
px = img.load()
for y in range(H):
    color = lerp(BG_TOP, BG_BOTTOM, y / (H - 1))
    for x in range(W):
        px[x, y] = color

draw = ImageDraw.Draw(img, "RGBA")

# Safe circular composition for Facebook's profile crop.
draw.ellipse((82, 82, 942, 942), fill=ACCENT_SOFT + (155,), outline=BORDER + (255,), width=4)
draw.ellipse((164, 164, 860, 860), fill=(251, 252, 251, 226), outline=(255, 255, 255, 180), width=2)

# Very soft technical grid inside the crop.
mask = Image.new("L", (W, H), 0)
mdraw = ImageDraw.Draw(mask)
mdraw.ellipse((164, 164, 860, 860), fill=255)
grid = Image.new("RGBA", (W, H), (0, 0, 0, 0))
gdraw = ImageDraw.Draw(grid)
for x in range(128, 896, 56):
    gdraw.line([(x, 132), (x, 892)], fill=ACCENT + (16,), width=1)
for y in range(128, 896, 56):
    gdraw.line([(132, y), (892, y)], fill=ACCENT + (16,), width=1)
grid_alpha = grid.getchannel("A")
grid.putalpha(Image.eval(grid_alpha, lambda a: a))
grid.putalpha(Image.composite(grid.getchannel("A"), Image.new("L", (W, H), 0), mask))
img = Image.alpha_composite(img.convert("RGBA"), grid)
draw = ImageDraw.Draw(img, "RGBA")

# Shadow behind the mark.
shadow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
sdraw = ImageDraw.Draw(shadow)
sdraw.rounded_rectangle((250, 246, 774, 760), radius=24, fill=(28, 31, 35, 24))
shadow = shadow.filter(ImageFilter.GaussianBlur(22))
img = Image.alpha_composite(img, shadow)
draw = ImageDraw.Draw(img, "RGBA")

# DoktorHaus-inspired house + diagnostic pulse mark.
stroke = 26
cx, cy = 512, 508
roof = [(278, cy - 90), (512, cy - 300), (746, cy - 90)]
draw.line(roof, fill=TEXT + (255,), width=stroke, joint="curve")
draw.line([(324, cy - 72), (324, cy + 236), (700, cy + 236), (700, cy - 72)], fill=TEXT + (255,), width=stroke, joint="curve")

pulse = [
    (196, cy + 8),
    (330, cy + 8),
    (374, cy - 78),
    (444, cy + 250),
    (522, cy - 26),
    (598, cy + 130),
    (690, cy + 98),
    (828, cy + 160),
]
draw.line(pulse, fill=ACCENT + (255,), width=stroke, joint="curve")

OUT.parent.mkdir(parents=True, exist_ok=True)
img.convert("RGB").save(OUT, quality=96)
print(OUT)
