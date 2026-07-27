"""Generate the GitHub social preview card (1280x640).

Shown whenever the repo is shared on X, Discord, Slack, LinkedIn, Reddit or
iMessage. Without one GitHub renders a generic card that looks the same as
every other repo, so the point here is to be recognisable at thumbnail size:
big name, one line of what it is, and the bird mark.
"""
from PIL import Image, ImageDraw, ImageFont

W, H = 1280, 640
BG = (18, 30, 40)           # deep slate
TEAL = (63, 184, 177)
WHITE = (246, 250, 250)
MUTED = (150, 176, 188)
ACCENT_BG = (26, 42, 54)

OUT = r"c:\Users\Zach_PCT\Desktop\AI Stuff\BirdNET Pi\BirdNET-Pi\docs\social-preview.png"
ICON = r"c:\Users\Zach_PCT\Desktop\AI Stuff\BirdNET Pi\BirdNET-Pi\homepage\images\pwa-512.png"

BOLD = "C:/Windows/Fonts/segoeuib.ttf"
REG = "C:/Windows/Fonts/segoeui.ttf"

img = Image.new("RGB", (W, H), BG)
d = ImageDraw.Draw(img)

# Soft teal wash in the lower right so the card isn't a flat rectangle
for i in range(260):
    a = i / 260
    y = H - 260 + i
    d.line([(0, y), (W, y)], fill=(
        int(BG[0] + (ACCENT_BG[0] - BG[0]) * a),
        int(BG[1] + (ACCENT_BG[1] - BG[1]) * a),
        int(BG[2] + (ACCENT_BG[2] - BG[2]) * a),
    ))

# Teal rule down the left edge
d.rectangle([0, 0, 10, H], fill=TEAL)

PAD = 74

# Bird mark, top right. The app icon is the bird on a solid indigo tile, which
# fights the dark card, so key the tile out. bird.png is already transparent but
# only 85px, too small to scale to this size cleanly.
try:
    icon = Image.open(ICON).convert("RGBA")
    px = icon.load()
    bg = px[4, 4][:3]
    TOL = 60
    for iy in range(icon.height):
        for ix in range(icon.width):
            r, g, b, a = px[ix, iy]
            dist = max(abs(r - bg[0]), abs(g - bg[1]), abs(b - bg[2]))
            if dist <= TOL:
                # Feather the last few units so edges don't alias into a fringe
                px[ix, iy] = (r, g, b, 0 if dist < TOL * 0.6 else int(a * (dist - TOL * 0.6) / (TOL * 0.4)))
    icon = icon.resize((190, 190), Image.LANCZOS)
    img.paste(icon, (W - PAD - 190, PAD - 4), icon)
except Exception as e:
    print("icon skipped:", e)

f_eyebrow = ImageFont.truetype(BOLD, 25)
f_title = ImageFont.truetype(BOLD, 78)
f_sub = ImageFont.truetype(REG, 35)
f_feat = ImageFont.truetype(REG, 27)

y = PAD + 6
d.text((PAD, y), "FEATURED BY BIRDNET", font=f_eyebrow, fill=TEAL)

y += 58
d.text((PAD, y), "BirdNET-Pi", font=f_title, fill=WHITE)
y += 88
d.text((PAD, y), "Enhanced", font=f_title, fill=TEAL)

y += 122
d.text((PAD, y), "Turn a Raspberry Pi into a 24/7", font=f_sub, fill=MUTED)
y += 48
d.text((PAD, y), "backyard bird observatory.", font=f_sub, fill=MUTED)

# Feature strip along the bottom
feats = ["Real-time bird ID", "Analytics", "Weather", "Insights"]
x = PAD
fy = H - 92
for i, t in enumerate(feats):
    if i:
        d.ellipse([x, fy + 13, x + 7, fy + 20], fill=TEAL)
        x += 26
    d.text((x, fy), t, font=f_feat, fill=MUTED)
    x += int(d.textlength(t, font=f_feat)) + 26

img.save(OUT, "PNG", optimize=True)
print("wrote", OUT, img.size)
