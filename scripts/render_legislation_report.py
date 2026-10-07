"""Render filtered public legislation to a PDF, using JSON stdin and PDF stdout."""
import io
import json
import sys
from html import escape
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Image, LongTable, TableStyle

class ReportTable(LongTable):
    def split(self, available_width, available_height):
        self.splitInRow = 0
        pieces = super().split(available_width, available_height)
        # Keep normal entries together; split only entries taller than a full page.
        if not pieces and max(self._rowHeights[1:] or [0]) > 495:
            self.splitInRow = 1
            pieces = super().split(available_width, available_height)
        return pieces


def render(data):
    root = Path(__file__).resolve().parent.parent
    font, bold = 'Times-Roman', 'Times-Bold'
    font_dir = Path('C:/Windows/Fonts')
    if (font_dir / 'times.ttf').is_file():
        pdfmetrics.registerFont(TTFont('ReportTimes', str(font_dir / 'times.ttf')))
        pdfmetrics.registerFont(TTFont('ReportTimesBold', str(font_dir / 'timesbd.ttf')))
        font, bold = 'ReportTimes', 'ReportTimesBold'
    else:
        for font_dir in [Path('/usr/share/fonts/truetype/dejavu')]:
            if (font_dir / 'DejaVuSerif.ttf').is_file():
                pdfmetrics.registerFont(TTFont('ReportTimes', str(font_dir / 'DejaVuSerif.ttf')))
                pdfmetrics.registerFont(TTFont('ReportTimesBold', str(font_dir / 'DejaVuSerif-Bold.ttf')))
                font, bold = 'ReportTimes', 'ReportTimesBold'
    cell = ParagraphStyle('Cell', fontName=font, fontSize=9, leading=11)
    heading_cell = ParagraphStyle('Column', parent=cell, fontName=bold, fontSize=9)
    heading = ParagraphStyle('Heading', fontName=bold, fontSize=17, leading=21, alignment=TA_CENTER)
    subheading = ParagraphStyle('Subheading', parent=heading, fontSize=12, leading=16)
    small = ParagraphStyle('Filters', parent=cell, fontSize=8, leading=10, alignment=TA_CENTER)
    def p(value, style=cell):
        return Paragraph(escape(str(value or 'N/A')).replace('\n', '<br/>'), style)
    output = io.BytesIO()
    doc = SimpleDocTemplate(output, pagesize=landscape(A4), leftMargin=12*mm, rightMargin=12*mm,
                            topMargin=10*mm, bottomMargin=13*mm,
                            title='Filtered Ordinances and Resolutions',
                            author='Sangguniang Panlungsod of Cagayan de Oro')
    logo = Image(str(root / 'public/assets/splogo.jpg'), width=19*mm, height=19*mm)
    logo.hAlign = 'CENTER'
    story = [logo, Spacer(1, 2*mm), p('Sangguniang Panlungsod of Cagayan de Oro', heading),
             p('[ ' + data['term'] + ' ]', subheading), p('List of Ordinances and Resolutions', subheading)]
    summary = '; '.join(str(k) + ': ' + str(v) for k, v in data['filters'].items() if v)
    if summary:
        story.extend([Spacer(1, 2*mm), p(summary, small)])
    story.extend([Spacer(1, 2*mm), p(str(len(data['entries'])) + ' matching result(s)', small), Spacer(1, 9*mm)])
    rows = [[p(label, heading_cell) for label in ['ORD./RES.\nNO.', 'TITLE', 'DATE\nAPPROVED', 'AUTHOR(S)', 'COMMITTEE(S)', 'STATUS']]]
    for entry in data['entries']:
        authors = list(dict.fromkeys(value for value in [entry['author'], entry['co_author']] if value and value != 'N/A'))
        values = [entry['kind'].capitalize() + '\n' + entry['number'], entry['title'],
                  entry['approved_date'], '; '.join(authors) or 'N/A',
                  '; '.join(entry['committees']) or 'N/A', 'Approved in the Plenary']
        rows.append([p(value) for value in values])
    if not data['entries']:
        rows.append([p('No legislation matches the selected filters.')] + ['']*5)
    table = ReportTable(rows, colWidths=[26*mm, 84*mm, 25*mm, 40*mm, 53*mm, 45*mm], repeatRows=1,
                        hAlign='LEFT', splitByRow=1, splitInRow=0)
    table.setStyle(TableStyle([
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('LINEBELOW', (0, 0), (-1, 0), 1.8, colors.black),
        ('LEFTPADDING', (0, 0), (-1, -1), 3), ('RIGHTPADDING', (0, 0), (-1, -1), 6),
        ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 7),
    ]))
    if not data['entries']:
        table.setStyle(TableStyle([('SPAN', (0, 1), (-1, 1))]))
    story.append(table)
    def footer(canvas, document):
        canvas.saveState()
        canvas.setFont(font, 8)
        canvas.drawRightString(285*mm, 7*mm, 'Page ' + str(document.page))
        canvas.restoreState()
    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    return output.getvalue()


if __name__ == '__main__':
    sys.stdout.buffer.write(render(json.load(sys.stdin)))
