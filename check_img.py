import fitz
doc = fitz.open('Паспорт на радиомодем 2576 ver.2 45.03.0001.222.00 ПС 1.pdf')
for i, page in enumerate(doc):
    for img in page.get_images(full=True):
        xref = img[0]
        base_image = doc.extract_image(xref)
        print(f"Page: {i}, img index: {img}, Ext: {base_image['ext']}, Size: {len(base_image['image'])} bytes, Dimensions: {base_image['width']}x{base_image['height']}")
