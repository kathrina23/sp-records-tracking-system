import tempfile
import unittest
import zipfile
from pathlib import Path
from read_legislation_excel import read_rows

class ExcelReaderTests(unittest.TestCase):
    def workbook(self, path, formula=''):
        with zipfile.ZipFile(path, 'w') as archive:
            archive.writestr('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Entries" sheetId="1" r:id="rId1"/></sheets></workbook>')
            archive.writestr('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
            archive.writestr('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Resolution</t></si></sst>')
            archive.writestr('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Type</t></is></c></row><row r="3"><c r="A3" t="s"><v>0</v></c><c r="B3" t="inlineStr"><is><t>00012-2026</t></is></c><c r="C3" t="inlineStr"><is><t>Unicode ₱ &amp; title</t></is></c><c r="D3">' + formula + '<v>46251</v></c></row></sheetData></worksheet>')

    def test_cells_gaps_and_identifiers(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'test.xlsx'
            self.workbook(path)
            rows = read_rows(path, 'xlsx')
            self.assertEqual(rows[1], [])
            self.assertEqual(rows[2][:4], ['Resolution', '00012-2026', 'Unicode ₱ & title', '46251'])

    def test_formulas_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'test.xlsx'
            self.workbook(path, '<f>TODAY()</f>')
            with self.assertRaisesRegex(ValueError, 'formula'):
                read_rows(path, 'xlsx')

    def test_csv_unicode(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'test.csv'
            path.write_text('\ufeffType,Number,Title / Subject,Date Approved\nOrdinance,0001,Unicode ₱,2026-08-17\n', encoding='utf-8')
            self.assertEqual(read_rows(path, 'csv')[1][1:3], ['0001', 'Unicode ₱'])

if __name__ == '__main__':
    unittest.main()
