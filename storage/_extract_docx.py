import zipfile, re, sys

def docx_text(path):
    with zipfile.ZipFile(path) as z:
        with z.open("word/document.xml") as f:
            raw = f.read().decode("utf-8", "ignore")
    # strip XML tags
    text = re.sub(r"<w:[\w-]+[^>]*>", " ", raw)
    text = re.sub(r"</w:[\w-]+>", " ", text)
    text = re.sub(r"&[a-z]+;", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text

for f in ["ROUTLAW_FRD_v2.0-draft.docx", "ROUTLAW_BRD_v2.0-draft.docx"]:
    t = docx_text(f)
    out = "storage/_tmp_" + f.replace(".docx", ".txt")
    with open(out, "w", encoding="utf-8") as fh:
        fh.write(t)
    print(f"{f} -> {out} ({len(t)} chars)", file=sys.stderr)
