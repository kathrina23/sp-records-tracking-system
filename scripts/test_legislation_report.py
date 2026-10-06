import io
from pathlib import Path
from pypdf import PdfReader
import pypdfium2 as pdfium
from render_legislation_report import render

folder = Path(__file__).resolve().parent.parent / 'tmp/pdfs'
folder.mkdir(parents=True, exist_ok=True)
entries = []
for index in range(45):
    entries.append({'kind': 'resolution', 'number': 'QA-' + str(index),
                    'title': 'RESOLUTION APPROVING A COMMUNITY PROGRAM WITH A BUDGET OF ₱600,000.00 - ' + ('PUBLIC SERVICES AND COMMUNITY DEVELOPMENT ' * 8),
                    'approved_date': '2026-10-04', 'author': 'José Dela Cruz', 'co_author': 'María Santos',
                    'committees': ['Committee on Finance', 'Committee on Social Services']})
pdf = render({'entries': entries, 'term': '22nd Sangguniang Panlungsod', 'filters': {'Type': 'Resolution'}})
reader = PdfReader(io.BytesIO(pdf))
text = '\n'.join(page.extract_text() for page in reader.pages)
assert len(reader.pages) > 1
assert 'Sangguniang Panlungsod of Cagayan de Oro' in text
assert 'José Dela Cruz' in text and '₱600,000.00' in text
for index in range(45):
    assert 'QA-' + str(index) in text
for page in reader.pages:
    assert 'AUTHOR(S)' in page.extract_text()
empty = render({'entries': [], 'term': 'All Terms', 'filters': {}})
assert 'No legislation matches' in PdfReader(io.BytesIO(empty)).pages[0].extract_text()
(folder / 'report-pagination-qa.pdf').write_bytes(pdf)
for filename in ['legislation-report.pdf', 'report-pagination-qa.pdf']:
    document = pdfium.PdfDocument(str(folder / filename))
    page = document[0]
    bitmap = page.render(scale=1.4)
    bitmap.to_pil().copy().save(folder / (filename + '.png'))
    if len(document) > 1:
        page = document[len(document) - 1]
        bitmap = page.render(scale=1.4)
        bitmap.to_pil().copy().save(folder / (filename + '-last.png'))
    document.close()
print('PASS: PDF layout, 45 results over multiple pages, repeated headers, Unicode text, and empty results.')
