"""Read an XLSX upload without executing formulas or external workbook links."""
import csv
import io
import json
import sys
import zipfile
import xml.etree.ElementTree as ET

NS = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}

def read_rows(path, extension):
    if extension == 'csv':
        with open(path, encoding='utf-8-sig', newline='') as source:
            rows = list(csv.reader(source))
        if len(rows) > 1001:
            raise ValueError('Limit each upload to 1,000 entries.')
        return rows
    with zipfile.ZipFile(path) as archive:
        if sum(item.file_size for item in archive.infolist()) > 30 * 1024 * 1024:
            raise ValueError('The expanded workbook exceeds 30 MB.')
        def xml(name):
            data = archive.read(name)
            if b'<!DOCTYPE' in data.upper() or b'<!ENTITY' in data.upper():
                raise ValueError('Unsupported XML declarations in workbook.')
            return ET.fromstring(data)
        workbook = xml('xl/workbook.xml')
        properties = workbook.find('s:workbookPr', NS)
        if properties is not None and properties.get('date1904') in ('1', 'true'):
            raise ValueError('Use the standard Excel 1900 date system or ISO date text.')
        sheets = workbook.find('s:sheets', NS)
        sheet = next((item for item in sheets if item.get('state', 'visible') == 'visible'), None)
        if sheet is None:
            raise ValueError('No visible worksheet found.')
        relation_id = sheet.get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id')
        relations = xml('xl/_rels/workbook.xml.rels')
        relation = next(item for item in relations if item.get('Id') == relation_id)
        target = relation.get('Target', '')
        if relation.get('TargetMode') == 'External' or '..' in target:
            raise ValueError('Unsupported worksheet reference.')
        target = target.lstrip('/') if target.startswith('/') else 'xl/' + target
        shared = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            shared = [''.join(item.itertext()) for item in xml('xl/sharedStrings.xml').findall('s:si', NS)]
        rows = []
        for row in xml(target).findall('s:sheetData/s:row', NS):
            values = [''] * 32
            for cell in row.findall('s:c', NS):
                address = cell.get('r', '')
                column = 0
                for char in address:
                    if not char.isalpha():
                        break
                    column = column * 26 + ord(char.upper()) - 64
                if column < 1 or column > 32:
                    raise ValueError('Use at most 32 columns in the worksheet.')
                if cell.find('s:f', NS) is not None:
                    raise ValueError(f'Cell {address} contains a formula. Paste values before importing.')
                value = cell.findtext('s:v', default='', namespaces=NS)
                if cell.get('t') == 's':
                    value = shared[int(value)]
                elif cell.get('t') == 'inlineStr':
                    value = ''.join(cell.find('s:is', NS).itertext())
                elif cell.get('t') == 'e':
                    raise ValueError(f'Cell {address} contains an Excel error.')
                values[column - 1] = value
            # Retain gaps so validation reports the actual worksheet row.
            row_number = int(row.get('r', len(rows) + 1))
            if row_number > 1001:
                raise ValueError('Limit each upload to 1,000 entries starting at row 2.')
            while len(rows) < row_number - 1:
                rows.append([])
            rows.append(values)
        return rows

if __name__ == '__main__':
    try:
        print(json.dumps({'rows': read_rows(sys.argv[1], sys.argv[2])}, ensure_ascii=True))
    except Exception as error:
        print(json.dumps({'error': str(error)}))
        sys.exit(1)
