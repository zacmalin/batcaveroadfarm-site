import base64, hashlib, io, json, math, os, pathlib, zipfile
from PIL import Image, ImageDraw, ImageFont
ROOT = pathlib.Path(__file__).parent
OUT = ROOT / "output"
OUT.mkdir(exist_ok=True)
manifest_path = ROOT / "manifest.json"
if not manifest_path.exists():
    raise SystemExit("Missing botanical-v4/manifest.json. Upload the supplied 202-icon manifest before running.")
data = json.loads(manifest_path.read_text())
icons = data["icons"]
assert len(icons) == 202 and len({i["id"] for i in icons}) == 202, "Manifest must have 202 unique identifiers"
batch = int(os.environ.get("BATCH", "1"))
assert 1 <= batch <= 7
selected = icons[(batch-1)*30:batch*30]
assert len(selected) == (30 if batch < 7 else 22)
generate = os.environ.get("GENERATE", "false").lower() == "true"
if not generate:
    print(f"DRY RUN PASSED: manifest contains {len(icons)} unique identifiers; batch {batch} has {len(selected)} unique plants. No API requests or artwork generated.")
    raise SystemExit(0)
if generate and not os.environ.get("OPENAI_API_KEY"):
    raise SystemExit("OPENAI_API_KEY secret is required for image generation")
client = None
if generate:
    from openai import OpenAI
    client = OpenAI()
png_dir = OUT / "png"
webp_dir = OUT / "webp"
png_dir.mkdir(exist_ok=True)
webp_dir.mkdir(exist_ok=True)
issues = []
digests = set()
for idx, plant in enumerate(selected, 1):
    ident = plant["id"]
    png = png_dir / (ident + ".png")
    webp = webp_dir / (ident + ".webp")
    if generate:
        prompt = ("Single isolated botanical watercolor illustration of " + plant["name"] +
          ", botanically accurate recognizable foliage, flowers and harvestable parts. "
          "Rustic natural watercolor, richly detailed, legible at garden map size. "
          "True transparent background with transparent spaces between stems and leaves. "
          "No text, border, pot, shadow, backdrop, checkerboard, colored matte or decorative objects.")
        try:
            response = client.images.generate(model="gpt-image-1.5", prompt=prompt,
                size="1024x1024", background="transparent", output_format="png")
            png.write_bytes(base64.b64decode(response.data[0].b64_json))
        except Exception as exc:
            issues.append(f"{ident}: generation failed: {exc}")
            continue
    if not png.exists():
        issues.append(f"{ident}: PNG missing")
        continue
    try:
        im = Image.open(png).convert("RGBA")
        alpha = im.getchannel("A")
        extrema = alpha.getextrema()
        if extrema[0] != 0 or extrema[1] != 255:
            raise ValueError("not genuinely transparent with opaque plant pixels")
        if im.getpixel((0,0))[3] != 0:
            raise ValueError("top-left background is not transparent")
        bbox = alpha.getbbox()
        if not bbox:
            raise ValueError("empty image")
        im.thumbnail((236,236), Image.Resampling.LANCZOS)
        icon = Image.new("RGBA",(256,256),(0,0,0,0))
        icon.alpha_composite(im,((256-im.width)//2,(256-im.height)//2))
        digest = hashlib.sha256(icon.tobytes()).hexdigest()
        if digest in digests:
            raise ValueError("duplicate pixel-identical artwork")
        digests.add(digest)
        icon.save(png)
        icon.save(webp, "WEBP", lossless=True, method=6)
        if Image.open(webp).size != (256,256):
            raise ValueError("invalid WebP size")
    except Exception as exc:
        issues.append(f"{ident}: {exc}")
        png.unlink(missing_ok=True)
        webp.unlink(missing_ok=True)
if issues:
    (OUT / "qa_failures.txt").write_text("\n".join(issues))
    raise SystemExit(f"{len(issues)} assets failed QA; no approved ZIPs produced")
batch_tag = f"batch{batch:02d}"
entries = []
for i in selected:
    entries.append({"id":i["id"],"name":i["name"],"category":i.get("category","unspecified"),
        "png":"png/"+i["id"]+".png","webp":"webp/"+i["id"]+".webp"})
(OUT/"manifest.json").write_text(json.dumps({"version":4,"batch":batch,"count":len(entries),"icons":entries},indent=2))
def proof(background, filename):
    w, h = 6*292+24, 5*312+24
    if background == "checker":
        sheet = Image.new("RGB",(w,h),"white")
        d = ImageDraw.Draw(sheet)
        for y in range(0,h,24):
            for x in range(0,w,24):
                if (x//24+y//24)%2:
                    d.rectangle((x,y,x+23,y+23),fill="#dddddd")
    else:
        sheet = Image.new("RGB",(w,h),background)
    d = ImageDraw.Draw(sheet)
    for n,item in enumerate(selected):
        x=24+(n%6)*292; y=16+(n//6)*312
        icon=Image.open(png_dir/(item["id"]+".png")).convert("RGBA")
        sheet.paste(icon,(x+18,y),icon)
        ink="#ffffff" if background=="#000000" else "#222222"
        d.text((x+12,y+261),f"{n+1:02d}  {item['name']}",fill=ink)
    sheet.save(OUT/filename)
proof("#ffffff","proof_white.png")
proof("#000000","proof_black.png")
proof("#9aa88b","proof_sage.png")
proof("checker","proof_checker.png")
def archive(name, files):
    with zipfile.ZipFile(OUT/name,"w",zipfile.ZIP_DEFLATED) as z:
        for f,arc in files:
            z.write(f,arc)
        z.write(OUT/"manifest.json","manifest.json")
archive(f"bcrf_botanical_v4_{batch_tag}_png.zip",[(png_dir/(i["id"]+".png"),"png/"+i["id"]+".png") for i in selected])
archive(f"bcrf_botanical_v4_{batch_tag}_webp.zip",[(webp_dir/(i["id"]+".webp"),"webp/"+i["id"]+".webp") for i in selected])
archive(f"bcrf_botanical_v4_{batch_tag}_proofs.zip",[(OUT/("proof_"+b+".png"),"proof_"+b+".png") for b in ("white","black","sage","checker")])
print(f"Batch {batch} complete: {len(selected)} unique PNG/WebP pairs, four proof sheets, three ZIPs. Visual review still required.")
