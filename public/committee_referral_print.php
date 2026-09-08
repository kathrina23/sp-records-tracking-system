<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_committee_reporting_schema();
ensure_plenary_number_schema();

$id = (int) ($_GET['id'] ?? 0);
$movementId = (int) ($_GET['movement_id'] ?? 0);
$stmt = db()->prepare("SELECT r.*, c.name committee_name, c.id committee_id, clerk.name receiving_clerk_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    LEFT JOIN users clerk ON clerk.id = r.receiving_clerk_id
    WHERE r.id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record || $record['document_type'] !== 'Committee Referrals' || empty($record['committee_id']) || ($record['status'] ?? '') === 'Received') {
    http_response_code(404);
    exit('Committee Referral is not yet reviewed and ready for printing.');
}

if (!can_view_record_materials($record)) {
    http_response_code(403);
    exit('You are not allowed to view this Committee Referral.');
}

$printStampStmt = db()->prepare("SELECT m.created_at, u.name printed_by_name
    FROM record_movements m
    LEFT JOIN users u ON u.id = m.updated_by
    WHERE m.record_id = ?
    AND m.notes = 'Committee Referral print timestamp generated.'
    AND u.role = 'receiving_clerk'
    ORDER BY m.id ASC
    LIMIT 1");
$printStampStmt->execute([$id]);
$printStamp = $printStampStmt->fetch();
if (!$printStamp && (current_user()['role'] ?? '') === 'receiving_clerk') {
    $stampInsert = db()->prepare("INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stampInsert->execute([
        $id,
        $record['status'],
        $record['status'],
        $record['current_location'],
        $record['current_location'],
        'Committee Referral print timestamp generated.',
        $record['title'],
        current_user()['id'],
    ]);
    $printStampStmt->execute([$id]);
    $printStamp = $printStampStmt->fetch();
}

$termStmt = db()->query('SELECT id, name FROM committee_terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
$term = $termStmt->fetch();
if (!$term) {
    $term = db()->query('SELECT id, name FROM committee_terms ORDER BY start_year DESC, id DESC LIMIT 1')->fetch();
}
$generatedStamp = (string) ($printStamp['created_at'] ?? date('Y-m-d H:i:s'));

audit_log('committee_referral_print_view', 'Opened printable Committee Referral ' . $record['control_number'] . '.', 'record', $id);

$sameNumberStmt = db()->prepare('SELECT COUNT(*) FROM records WHERE control_number = ? AND id <> ?');
$sameNumberStmt->execute([$record['control_number'], $id]);
$hasCarriedCommunicationNumber = (int) $sameNumberStmt->fetchColumn() > 0
    || str_contains((string) ($record['remarks'] ?? ''), 'Carried from Communication Number')
    || str_contains((string) ($record['remarks'] ?? ''), 'Created from Communication Number');

$reportMovement = null;
if ($movementId > 0) {
    $movementStmt = db()->prepare("SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.name) updated_by_name, u.role updated_by_role
        FROM record_movements m
        LEFT JOIN users u ON u.id = m.updated_by
        WHERE m.id = ? AND m.record_id = ?
        LIMIT 1");
    $movementStmt->execute([$movementId, $id]);
    $reportMovement = $movementStmt->fetch() ?: null;
}
$hideCitySecretarySignatureImage = (string) ($reportMovement['updated_by_role'] ?? '') === 'secretariat';

$lawsAndRulesPrintTitle = is_laws_and_rules_secretariat()
    && $movementId === 0
        ? trim((string) ($record['plenary_print_title'] ?? ''))
        : '';
$reportTitle = $lawsAndRulesPrintTitle !== ''
    ? $lawsAndRulesPrintTitle
    : (trim((string) ($reportMovement['record_title'] ?? '')) !== ''
        ? (string) $reportMovement['record_title']
        : (trim((string) ($reportMovement['report_title'] ?? '')) !== ''
            ? (string) $reportMovement['report_title']
            : (string) $record['title']));
$showPerusal = (string) ($reportMovement['to_status'] ?? ($movementId === 0 ? ($record['status'] ?? '') : '')) === 'Perusal';

$secretaryStmt = db()->query("SELECT name FROM users WHERE role = 'city_secretary' AND is_active = 1 ORDER BY id LIMIT 1");
$citySecretaryName = (string) ($secretaryStmt->fetchColumn() ?: 'City Secretary');
$exOfficioMembers = [];
if ($term) {
    $exOfficioStmt = db()->prepare("SELECT name, position, officer_role
        FROM city_officials
        WHERE term_id = ?
        AND (position = 'Vice Mayor' OR officer_role IN ('Majority Floor Leader', 'Minority Floor Leader'))
        ORDER BY FIELD(position, 'Vice Mayor', 'City Councilor'), FIELD(officer_role, 'Majority Floor Leader', 'Minority Floor Leader'), name");
    $exOfficioStmt->execute([(int) $term['id']]);
    $exOfficioMembers = $exOfficioStmt->fetchAll();
}
$floorLeaders = array_values(array_filter($exOfficioMembers, fn ($person) => in_array($person['officer_role'] ?? '', ['Majority Floor Leader', 'Minority Floor Leader'], true)));
$viceMayors = array_values(array_filter($exOfficioMembers, fn ($person) => ($person['position'] ?? '') === 'Vice Mayor'));

$committeeRows = record_committee_rows($id, (int) $record['committee_id']);
$recordCommitteeIds = array_map(fn ($row) => (int) $row['committee_id'], $committeeRows);
$reportMovementByCommittee = [];
if ($recordCommitteeIds) {
    $movementCandidatesStmt = db()->prepare("SELECT m.*, u.role updated_by_role
        FROM record_movements m
        LEFT JOIN users u ON u.id = m.updated_by
        WHERE m.record_id = ?
        AND COALESCE(m.notes, '') <> 'Committee Referral print timestamp generated.'
        ORDER BY CASE WHEN m.id = ? THEN 0 ELSE 1 END, m.created_at DESC, m.id DESC");
    $movementCandidatesStmt->execute([$id, $movementId]);
    foreach ($movementCandidatesStmt->fetchAll() as $candidateMovement) {
        $candidateCommitteeIds = [];
        $updatedBy = (int) ($candidateMovement['updated_by'] ?? 0);
        $updatedByRole = (string) ($candidateMovement['updated_by_role'] ?? '');
        if ($updatedBy > 0 && $updatedByRole === 'secretariat') {
            $candidateCommitteeIds = array_values(array_intersect(secretariat_committee_ids($updatedBy), $recordCommitteeIds));
        } elseif ($updatedBy > 0 && $updatedByRole === 'division_chief') {
            $candidateCommitteeIds = array_values(array_intersect(division_chief_committee_ids($updatedBy), $recordCommitteeIds));
        }

        if (!$candidateCommitteeIds && (int) ($candidateMovement['id'] ?? 0) === $movementId) {
            $candidateCommitteeIds = $recordCommitteeIds;
        }

        foreach ($candidateCommitteeIds as $candidateCommitteeId) {
            if (!isset($reportMovementByCommittee[(int) $candidateCommitteeId])) {
                $reportMovementByCommittee[(int) $candidateCommitteeId] = $candidateMovement;
            }
        }
    }
}
$committeeCodes = [];
$lawsAndRulesCommitteeId = 0;
foreach ($committeeRows as $committeeRow) {
    if (strtolower(trim((string) ($committeeRow['committee_name'] ?? ''))) === 'laws and rules') {
        $lawsAndRulesCommitteeId = (int) $committeeRow['committee_id'];
        break;
    }
}
$currentPrintCommitteeId = 0;
// Receiving Section prints the full referral set. Secretariat and other
// role-specific views retain the current-committee referral selection.
$limitPrintToCurrentCommittee = (current_user()['role'] ?? '') !== 'receiving_clerk';
$movementUpdaterId = (int) ($reportMovement['updated_by'] ?? 0);
$movementUpdaterRole = (string) ($reportMovement['updated_by_role'] ?? '');
if ($movementId > 0 && $movementUpdaterId > 0) {
    $movementCommitteeIds = [];
    if ($movementUpdaterRole === 'secretariat') {
        $movementCommitteeIds = secretariat_committee_ids($movementUpdaterId);
    } elseif ($movementUpdaterRole === 'division_chief') {
        $movementCommitteeIds = division_chief_committee_ids($movementUpdaterId);
    }
    $movementCommitteeIds = array_values(array_intersect($movementCommitteeIds, $recordCommitteeIds));
    $currentPrintCommitteeId = (int) ($movementCommitteeIds[0] ?? 0);
}
if ($currentPrintCommitteeId === 0) {
    $currentRole = (string) (current_user()['role'] ?? '');
    if ($currentRole === 'secretariat') {
        $viewerCommitteeIds = array_values(array_intersect(
            secretariat_committee_ids((int) current_user()['id']),
            $recordCommitteeIds
        ));
        if (is_laws_and_rules_secretariat() && in_array($lawsAndRulesCommitteeId, $viewerCommitteeIds, true)) {
            $currentPrintCommitteeId = $lawsAndRulesCommitteeId;
        } else {
            $currentPrintCommitteeId = (int) ($viewerCommitteeIds[0] ?? 0);
        }
    } elseif ($currentRole === 'division_chief') {
        $viewerCommitteeIds = array_values(array_intersect(
            division_chief_committee_ids((int) current_user()['id']),
            $recordCommitteeIds
        ));
        $currentPrintCommitteeId = (int) ($viewerCommitteeIds[0] ?? 0);
    } elseif (in_array($currentRole, ['admin', 'city_secretary'], true)
        && $lawsAndRulesCommitteeId > 0
        && trim((string) ($record['plenary_print_title'] ?? '')) !== '') {
        $currentPrintCommitteeId = $lawsAndRulesCommitteeId;
    }
}
if ($currentPrintCommitteeId === 0 && in_array((int) $record['committee_id'], $recordCommitteeIds, true)) {
    $currentPrintCommitteeId = (int) $record['committee_id'];
}
if ($currentPrintCommitteeId === 0) {
    $currentPrintCommitteeId = (int) ($recordCommitteeIds[0] ?? 0);
}
if ($limitPrintToCurrentCommittee && $currentPrintCommitteeId > 0) {
    $currentCommitteeRows = array_values(array_filter(
        $committeeRows,
        fn ($row) => (int) $row['committee_id'] === $currentPrintCommitteeId
    ));
    if ($currentCommitteeRows) {
        $committeeRows = $currentCommitteeRows;
    }
}
if ($committeeRows) {
    $committeeIds = array_map(fn ($row) => (int) $row['committee_id'], $committeeRows);
    $codePlaceholders = implode(',', array_fill(0, count($committeeIds), '?'));
    try {
        $codeStmt = db()->prepare("SELECT id, committee_code FROM committees WHERE id IN ($codePlaceholders)");
        $codeStmt->execute($committeeIds);
        foreach ($codeStmt->fetchAll() as $codeRow) {
            $committeeCodes[(int) $codeRow['id']] = (string) ($codeRow['committee_code'] ?? '');
        }
    } catch (Throwable $error) {
        $committeeCodes = [];
    }
}
$totalReferrals = max(1, count($committeeRows));
$groupLabel = $totalReferrals > 1 ? referral_group_label($totalReferrals) : '';
$printItems = [];
$maxRosterCount = 0;
foreach ($committeeRows as $index => $committeeRow) {
    $roster = [];
    if ($term) {
        $rosterStmt = db()->prepare("SELECT name, position
            FROM committee_members
            WHERE committee_id = ? AND term_id = ?
            ORDER BY FIELD(position, 'Chairperson', 'Vice Chairperson', 'Member'), sort_order, id");
        $rosterStmt->execute([(int) $committeeRow['committee_id'], (int) $term['id']]);
        $roster = $rosterStmt->fetchAll();
    }
    $maxRosterCount = max($maxRosterCount, count($roster));
    $printItems[] = [
        'committee_id' => (int) $committeeRow['committee_id'],
        'committee_name' => $committeeRow['committee_name'],
        'committee_code' => $committeeCodes[(int) $committeeRow['committee_id']] ?? '',
        'report_number' => committee_report_number($id, (int) $committeeRow['committee_id'], $committeeCodes[(int) $committeeRow['committee_id']] ?? ''),
        'report_date' => display_date(($reportMovementByCommittee[(int) $committeeRow['committee_id']]['created_at'] ?? $reportMovement['created_at'] ?? '') ?: ''),
        'sequence_no' => (int) ($committeeRow['sequence_no'] ?? ($index + 1)),
        'roster' => $roster,
        'chairperson' => array_values(array_filter($roster, fn ($person) => $person['position'] === 'Chairperson')),
        'vice_chairperson' => array_values(array_filter($roster, fn ($person) => $person['position'] === 'Vice Chairperson')),
        'members' => array_values(array_filter($roster, fn ($person) => $person['position'] === 'Member')),
    ];
}

$titleLength = strlen(trim(preg_replace('/\s+/', ' ', $reportTitle)));
$signatoryCount = $maxRosterCount + count($exOfficioMembers) + 2;
$documentClasses = ['referral-document'];
if ($titleLength > 650 || $signatoryCount > 18) {
    $documentClasses[] = 'layout-dense';
} elseif ($titleLength > 350 || $signatoryCount > 14) {
    $documentClasses[] = 'layout-compact';
}
$publicStatusUrl = public_record_status_url((int) $record['id']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Committee Referral <?= e($record['control_number']) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/styles.css') ?>">
</head>
<body class="print-body">
    <main class="print-page">
        <div class="print-actions actions">
            <button class="btn" onclick="window.print()">Print Committee Referral</button>
            <a class="btn danger" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">X Close</a>
            <span class="muted">If the print dialog does not open here, press Ctrl+P or open this page in Chrome/Edge.</span>
        </div>

        <?php foreach ($printItems as $item): ?>
        <?php
            $committeeName = $item['committee_name'];
            $sequenceNo = (int) $item['sequence_no'];
            $groupNote = $totalReferrals > 1
                ? $groupLabel . ' ' . $sequenceNo . ' of ' . $totalReferrals
                : '';
        ?>
        <section class="<?= e(implode(' ', $documentClasses)) ?>">
            <div class="referral-details-section">
            <header class="referral-header">
                <img class="referral-logo" src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
                <div class="referral-header-copy">
                    <p class="referral-republic">Republic of the Philippines</p>
                    <p class="referral-city">Cagayan de Oro City</p>
                    <h1>OFFICE OF THE SANGGUNIANG PANLUNGSOD</h1>
                </div>
                <div class="referral-header-qr" aria-label="QR code for <?= e($record['control_number']) ?>">
                    <span class="communication-qr" data-communication-qr data-qr-value="<?= e($publicStatusUrl) ?>"></span>
                    <span>Scan status</span>
                </div>
            </header>

            <div class="referral-rule referral-header-rule"></div>

            <div class="referral-meta">
                <div class="referral-meta-item">
                    <span>Communication No.</span>
                    <strong><?= e($record['control_number']) ?></strong>
                </div>
                <div class="referral-meta-item referral-meta-date">
                    <span>Date Received</span>
                    <strong><?= e(display_date($record['received_date'] ?? '')) ?></strong>
                </div>
                <div class="referral-meta-item full">
                    <span>Client / Origin</span>
                    <strong><?= e($record['client_name'] ?? '') ?></strong>
                </div>
            </div>

            <div class="referral-title">
                <strong>SUBJECT / TITLE</strong>
                <p><?= e(display_record_title($reportTitle)) ?></p>
            </div>

            <p class="referral-paragraph">
                Respectfully referred to the <strong>COMMITTEE ON <?= e(strtoupper($committeeName ?? '')) ?></strong>
                the herein communication for study, investigation, report and/or recommendation.
            </p>

            <div class="city-secretary-block">
                <p>FOR THE CITY VICE MAYOR &amp; PRESIDING OFFICER:</p>
                <div class="signature-line">
                    <?php if (!$hideCitySecretarySignatureImage): ?>
                        <img class="city-secretary-signature" src="<?= url('/assets/city-secretary-signature.png') ?>" alt="">
                    <?php endif; ?>
                    <span><?= e(strtoupper($citySecretaryName)) ?></span>
                    <small>City Secretary</small>
                </div>
            </div>
            </div>

            <div class="referral-report-half">
            <div class="referral-rule"></div>

            <?php if ($hasCarriedCommunicationNumber): ?>
                <div class="referral-carry-note">Note: Same Communication Number carried to this committee referral.</div>
            <?php endif; ?>

            <?php if ($showPerusal || $groupNote !== ''): ?>
                <div class="report-top-row">
                    <?php if ($showPerusal): ?>
                        <div class="referral-perusal-note">Perusal</div>
                    <?php endif; ?>
                    <?php if ($groupNote !== ''): ?>
                        <div class="referral-group-note"><?= e($groupNote) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <section class="committee-report-section">
                <div class="section-heading">
                    <h2>COMMITTEE REPORT</h2>
                </div>
                <p class="report-number">
                    <span>Report No. <?= e($item['report_number']) ?></span>
                    <span class="report-number-date">Date:<span><?= e($item['report_date']) ?></span></span>
                </p>
                <div class="report-writing-box" aria-label="Committee report writing area"></div>

                <div class="signature-grid committee-roster-grid">
                    <?php foreach ($item['chairperson'] as $person): ?>
                        <div class="signature-line">
                            <span>HON. <?= e(strtoupper($person['name'])) ?></span>
                            <small>Chairperson</small>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($item['vice_chairperson'] as $person): ?>
                        <div class="signature-line">
                            <span>HON. <?= e(strtoupper($person['name'])) ?></span>
                            <small>Vice Chairperson</small>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($item['members'] as $person): ?>
                        <div class="signature-line">
                            <span>HON. <?= e(strtoupper($person['name'])) ?></span>
                            <small>Member</small>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$item['roster']): ?>
                        <p class="muted">No committee roster found for the current term.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="committee-report-section ex-officio-section">
                <div class="section-heading section-heading-subtle">
                    <h2>EX-OFFICIO MEMBERS</h2>
                </div>
                <div class="signature-grid ex-officio-grid">
                    <?php foreach ($floorLeaders as $person): ?>
                        <div class="signature-line">
                            <span>HON. <?= e(strtoupper($person['name'])) ?></span>
                            <small><?= e($person['position']) ?></small>
                            <small><?= e($person['officer_role']) ?></small>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($viceMayors as $person): ?>
                        <div class="signature-line ex-officio-vice-mayor">
                            <span>HON. <?= e(strtoupper($person['name'])) ?></span>
                            <small><?= e($person['position']) ?></small>
                            <?php if (!empty($person['officer_role'])): ?>
                                <small><?= e($person['officer_role']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$exOfficioMembers): ?>
                        <p class="muted">No ex-officio members found for the current term.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="committee-report-section dissenting-section">
                <div class="section-heading section-heading-subtle">
                    <h2>DISSENTING</h2>
                </div>
                <div class="signature-grid">
                    <div class="signature-line blank-signature"><span>&nbsp;</span></div>
                    <div class="signature-line blank-signature"><span>&nbsp;</span></div>
                </div>
            </section>

            <p class="generated-note">
                Generated from the SP Records Tracking System - <?= e(display_datetime($generatedStamp)) ?>.
            </p>
            </div>
        </section>
        <?php endforeach; ?>
    </main>
    <script src="<?= url('/assets/vendor/qrcode-generator.js') ?>"></script>
    <script src="<?= url('/assets/communication-qr.js') ?>"></script>
    <script>
    (() => {
        const toNumber = (value) => Number.parseFloat(value || '0') || 0;
        const scaleElement = (element, base, scale, minimum, lineHeight = 1.08) => {
            if (!element || !base) {
                return;
            }

            element.style.fontSize = `${Math.max(minimum, base.fontSize * scale)}px`;
            element.style.lineHeight = String(Math.max(1.02, lineHeight));
        };
        const fitReferralDetails = () => {
            document.querySelectorAll('.referral-document').forEach((documentPage) => {
                const details = documentPage.querySelector('.referral-details-section');
                if (!details) {
                    return;
                }

                const title = details.querySelector('.referral-title p');
                const paragraph = details.querySelector('.referral-paragraph');
                const titleWrap = details.querySelector('.referral-title');
                const secretaryBlock = details.querySelector('.city-secretary-block');
                const secretarySignature = details.querySelector('.city-secretary-signature');
                const secretaryText = details.querySelector('.city-secretary-block p');

                [title, paragraph, titleWrap, secretaryBlock, secretarySignature, secretaryText].forEach((element) => {
                    if (element) {
                        element.removeAttribute('style');
                    }
                });

                const titleBase = title ? {
                    fontSize: toNumber(getComputedStyle(title).fontSize),
                } : null;
                const paragraphBase = paragraph ? {
                    fontSize: toNumber(getComputedStyle(paragraph).fontSize),
                } : null;

                const maxHeight = Math.floor(details.clientHeight) - 2;
                details.style.maxHeight = `${maxHeight}px`;

                const fits = () => details.scrollHeight <= maxHeight;
                if (fits()) {
                    return;
                }

                for (let scale = 0.96; scale >= 0.66; scale -= 0.02) {
                    scaleElement(title, titleBase, scale, 7.2, 1.04);
                    scaleElement(paragraph, paragraphBase, Math.max(scale, 0.78), 8.2, 1.05);
                    if (titleWrap) {
                        titleWrap.style.marginBottom = `${Math.max(3, 8 * scale)}px`;
                        titleWrap.style.paddingBottom = `${Math.max(3, 7 * scale)}px`;
                    }
                    if (secretaryBlock) {
                        secretaryBlock.style.marginTop = `${Math.max(8, 24 * scale)}px`;
                        secretaryBlock.style.marginBottom = `${Math.max(2, 8 * scale)}px`;
                    }
                    if (secretaryText) {
                        secretaryText.style.marginBottom = `${Math.max(3, 7 * scale)}px`;
                    }
                    if (secretarySignature) {
                        secretarySignature.style.height = `${Math.max(0.58, 0.86 * scale)}in`;
                        secretarySignature.style.width = `${Math.max(2, 2.62 * scale)}in`;
                    }
                    if (fits()) {
                        return;
                    }
                }

                if (secretaryBlock) {
                    secretaryBlock.style.marginTop = '4px';
                    secretaryBlock.style.marginBottom = '0';
                }
                if (secretaryText) {
                    secretaryText.style.marginBottom = '2px';
                }
                if (secretarySignature) {
                    secretarySignature.style.height = '.52in';
                    secretarySignature.style.width = '1.85in';
                    secretarySignature.style.marginBottom = '-17px';
                }
            });
        };

        window.addEventListener('load', fitReferralDetails);
        window.addEventListener('resize', fitReferralDetails);
        window.addEventListener('beforeprint', fitReferralDetails);
        if (window.matchMedia) {
            window.matchMedia('print').addEventListener('change', fitReferralDetails);
        }
        requestAnimationFrame(fitReferralDetails);
    })();
    </script>
</body>
</html>
