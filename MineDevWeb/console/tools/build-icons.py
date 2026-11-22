"""Generate MineDev PNG icons from the pixel block artwork."""

from pathlib import Path

from PIL import Image, ImageDraw


ASSETS = Path(__file__).resolve().parent.parent / "assets"


def render(size: int, maskable: bool = False) -> Image.Image:
    scale = 4
    canvas = Image.new("RGB", (size * scale, size * scale), "#101a12")
    draw = ImageDraw.Draw(canvas)
    inset = 0.16 if maskable else 0.0

    def box(coords, color, radius=0):
        left, top, right, bottom = coords
        coords_px = tuple(round((inset + value * (1 - 2 * inset)) * size * scale) for value in coords)
        if radius:
            draw.rounded_rectangle(coords_px, radius=round(radius * size * scale * (1 - 2 * inset)), fill=color)
        else:
            draw.rectangle(coords_px, fill=color)

    if not maskable:
        draw.rounded_rectangle((0, 0, size * scale - 1, size * scale - 1), radius=round(size * scale * 0.21), fill="#101a12")
    box((0.18, 0.18, 0.82, 0.82), "#263c28", 0.09)
    box((0.238, 0.238, 0.762, 0.762), "#91c85f")
    box((0.238, 0.238, 0.762, 0.309), "#b6e78b")
    box((0.238, 0.309, 0.309, 0.762), "#b6e78b")
    box((0.691, 0.309, 0.762, 0.762), "#b6e78b")
    box((0.238, 0.691, 0.762, 0.762), "#b6e78b")
    for x in (0.355, 0.535):
        for y in (0.355, 0.535):
            box((x, y, x + 0.11, y + 0.11), "#18301c")
            box((x + 0.016, y + 0.016, x + 0.094, y + 0.032), "#355e32")
    return canvas.resize((size, size), Image.Resampling.LANCZOS)


if __name__ == "__main__":
    for size in (180, 192, 512):
        render(size).save(ASSETS / f"icon-{size}.png", optimize=True)
    render(512, maskable=True).save(ASSETS / "icon-maskable-512.png", optimize=True)
