#!/usr/bin/env python3
import html
import json
import os
import re
import sys

ROOT = sys.argv[1]
OUT = sys.argv[2]
os.makedirs(OUT, exist_ok=True)

NEW_IDS = [5864,5866,5877,5900,5902,5906,5912,5918,5924,5926,5975,5979,5981,5984,5988,6001,6012,6014,6018,6022,6024,6026,6028,6035,6037,900050,900064]
OLD_IDS = [900060,900048,900043,6050,6048,6046,6044,6042,6033,6020,6008,5999,5995,5993,5990,5986,5904,5896,5892]
IDS = sorted(set(NEW_IDS + OLD_IDS))


def plain(s):
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'(?s)<[^>]+>', ' ', s or ''))).strip()


def replace_para(content, needle, new_html, required=True):
    for m in re.finditer(r'(?is)<p\b[^>]*>.*?</p>', content):
        if re.search(needle, plain(m.group(0)), re.I | re.S):
            return content[:m.start()] + new_html + content[m.end():]
    if required:
        raise SystemExit('PARAGRAPH_NOT_FOUND ' + needle)
    return content


def fix_text_nodes(content):
    parts = re.split(r'(<[^>]+>)', content)
    for i in range(0, len(parts), 2):
        s = parts[i]
        s = s.replace('In den Deutschland', 'In Deutschland').replace('in den Deutschland', 'in Deutschland')
        s = re.sub(r'[ \t]+([,.;:!?])', r'\1', s)
        parts[i] = s
    return ''.join(parts)


def transform(pid, content):
    content = content.replace(
        'https://doctorcura.com/ist-ein-online-rezept-legal-usa/',
        'https://doctorcura.com/ist-ein-online-rezept-legal-deutschland/'
    )
    content = fix_text_nodes(content)

    if pid == 5877:
        content = content.replace('in Deutschland und international', 'in Deutschland und anderen europäischen Ländern')

    if pid == 5979:
        content = content.replace(
            'Einige Wirkstoffe sind in Deutschland für das langfristige Gewichtsmanagement zugelassen',
            'Einige Wirkstoffe sind in der Europäischen Union für das langfristige Gewichtsmanagement zugelassen'
        )

    if pid == 5981:
        content = content.replace(
            'Wegovy ist in Deutschland zur langfristigen Gewichtskontrolle bei bestimmten Erwachsenen zugelassen - typischerweise bei Adipositas oder bei Übergewicht zusammen mit mindestens einer gewichtsbedingten Erkrankung.',
            'Wegovy ist in der Europäischen Union zur Gewichtsregulierung bei Erwachsenen mit Adipositas oder mit Übergewicht und mindestens einer gewichtsbedingten Begleiterkrankung zugelassen.'
        )
        content = replace_para(content, r'Mounjaro enthält Tirzepatid', '<p>Mounjaro enthält Tirzepatid. Dieser Wirkstoff aktiviert die Rezeptoren GIP und GLP-1 und beeinflusst dadurch unter anderem Appetit, Sättigung und Blutzuckerregulation. In der Europäischen Union ist Mounjaro zur Behandlung von Typ-2-Diabetes sowie – unter festgelegten Voraussetzungen – zum Gewichtsmanagement zugelassen. Welche Anwendung medizinisch passt, hängt von Diagnose, BMI, Begleiterkrankungen, bisherigen Therapien und möglichen Risiken ab.</p>')
        content = replace_para(content, r'medullären Schilddrüsenkarzinom', '<p>Seltene, aber ernsthafte Risiken müssen vor Beginn berücksichtigt werden. Dazu zählen unter anderem Bauchspeicheldrüsenentzündung, Gallenblasenprobleme, Dehydrierung durch starkes Erbrechen oder Durchfall sowie mögliche Veränderungen der Nierenfunktion. Gegenanzeigen und Warnhinweise unterscheiden sich je nach Präparat. Maßgeblich sind deshalb die aktuelle EU-Fachinformation und die individuelle ärztliche Beurteilung.</p>')

    if pid == 5984:
        content = replace_para(content, r'bundesstaatlichen Regeln', '<p>Online-Behandlungen in Deutschland unterliegen medizinischen und berufsrechtlichen Vorgaben. Ob eine Behandlung ausschließlich per Telemedizin erfolgen kann, hängt vom konkreten Einzelfall ab. Der behandelnde Arzt muss für die Versorgung entsprechend berechtigt sein und beurteilen, ob die Fernbehandlung medizinisch vertretbar ist und die erforderliche Sorgfalt gewahrt bleibt. Wenn eine persönliche Untersuchung notwendig ist, kann die Online-Anfrage abgelehnt oder eine Versorgung vor Ort empfohlen werden.</p>')

    if pid == 5988:
        content = replace_para(content, r'Mounjaro enthält den Wirkstoff Tirzepatid', '<p>Mounjaro enthält den Wirkstoff Tirzepatid. In der Europäischen Union ist es zur Behandlung von Typ-2-Diabetes und – unter festgelegten Voraussetzungen – auch zum Gewichtsmanagement bei Erwachsenen zugelassen. Der Wirkstoff beeinflusst unter anderem die Signalwege GIP und GLP-1, die eine Rolle bei Blutzuckerregulation, Sättigung und Magenentleerung spielen.</p>')
        content = replace_para(content, r'Für die langfristige Gewichtskontrolle gibt es', '<p>Viele Menschen interessieren sich für Tirzepatid wegen möglicher Gewichtsveränderungen während der Behandlung. Ein Wunsch nach <a href="https://doctorcura.com/ist-glp1-abnehmen-medizinisch-geeignet/">schnellem Gewichtsverlust</a> allein reicht jedoch nicht für eine Verordnung. Mounjaro ist in der Europäischen Union auch für das <a href="https://doctorcura.com/gewicht-reduzieren-mit-aerztlicher-begleitung/">langfristige Gewichtsmanagement</a> zugelassen, wenn die entsprechenden medizinischen Voraussetzungen erfüllt sind. Welche Therapie infrage kommt, hängt von BMI, Begleiterkrankungen, bisherigen Behandlungen und dem individuellen Risikoprofil ab.</p>')
        content = replace_para(content, r'medullärer Schilddrüsenkrebs|MEN 2', '<p>Mounjaro ist nicht für jede Patientin und jeden Patienten geeignet. Eine Überempfindlichkeit gegen Tirzepatid oder einen sonstigen Bestandteil ist eine Gegenanzeige. Weitere Vorerkrankungen, aktuelle Medikamente, Schwangerschaft oder frühere Unverträglichkeiten müssen vollständig angegeben werden, damit der Arzt Nutzen und Risiken anhand der aktuellen EU-Fachinformation beurteilen kann.</p>')
        content = replace_para(content, r'Pharmacy Benefit Plan', '<p>Die Kosten setzen sich je nach Anbieter aus der ärztlichen Prüfung, einer möglichen Betreuung, dem Medikament und dem Versand zusammen. Ob und in welcher Höhe eine Krankenversicherung Kosten übernimmt, hängt vom Tarif, der medizinischen Indikation und den jeweiligen Erstattungsregeln ab. Klären Sie deshalb vor Beginn, welche Leistungen übernommen werden und welche Kosten Sie selbst tragen müssen.</p>')

    if pid == 6001:
        content = content.replace(
            'sofern die ärztliche Genehmigung, die rechtlichen Vorgaben Ihres Bundesstaats und die Verfügbarkeit dies erlauben.',
            'sofern die Behandlung ärztlich genehmigt wurde und das Arzneimittel verfügbar ist.'
        )
        content = replace_para(content, r'bundesstaatliche und föderale Anforderungen', '<p>Für bestimmte verschreibungspflichtige oder besonders risikobehaftete Arzneimittel gelten zusätzliche Anforderungen. Deshalb kann nicht jedes Schlafmittel in jeder Situation ausschließlich telemedizinisch verordnet werden. Seriöse Anbieter kommunizieren diese Grenze klar und empfehlen bei Bedarf eine persönliche Untersuchung, statt eine Verschreibung vor der ärztlichen Prüfung zu versprechen.</p>')

    if pid == 6012:
        content = content.replace('nach den beste Medikamente gegen Schlafstörungen', 'nach den besten Medikamenten gegen Schlafstörungen')
        content = replace_para(content, r'In Deutschland sind mehrere verschreibungspflichtige Arzneimittel für Insomnie zugelassen', '<p>In Deutschland stehen für Insomnie unterschiedliche verschreibungspflichtige Behandlungsoptionen zur Verfügung. Sie unterscheiden sich bei Wirkungseintritt, Wirkdauer, Nebenwirkungen und Abhängigkeitspotenzial. Welche Therapie sinnvoll ist, sollte deshalb nach einer individuellen ärztlichen Prüfung entschieden werden.</p>')
        content = replace_para(content, r'Daridorexant, Lemborexant und Suvorexant', '<p>Orexin ist ein Botenstoff, der Wachheit fördert. Duale Orexin-Rezeptor-Antagonisten dämpfen dieses Wachsignal. Daridorexant ist in der Europäischen Union für Erwachsene mit Insomnie zugelassen, wenn die Beschwerden seit mindestens drei Monaten bestehen und die Leistungsfähigkeit am Tag deutlich beeinträchtigen.</p>')
        content = replace_para(content, r'Diese Medikamente können für Erwachsene mit wiederkehrenden Problemen', '<p>Bei Daridorexant müssen Dosierung, andere Medikamente, Leberfunktion und mögliche Wechselwirkungen ärztlich berücksichtigt werden. Auch Müdigkeit am Folgetag kann auftreten. Bei Narkolepsie darf Daridorexant nicht angewendet werden. Die Behandlung sollte regelmäßig überprüft und nur so lange wie medizinisch sinnvoll fortgeführt werden.</p>')
        content = re.sub(r'(?is)<h([1-6])\b([^>]*)>Z-Medikamente:\s*Zolpidem,\s*Eszopiclon und Zaleplon</h\1>', r'<h\1\2>Z-Medikamente: Zolpidem und Zopiclon</h\1>', content)
        content = replace_para(content, r'Zu den häufig verordneten Schlafmitteln gehören Zolpidem, Eszopiclon und Zaleplon', '<p>Zu den sogenannten Z-Medikamenten gehören unter anderem Zolpidem und Zopiclon. Sie können bei ausgeprägter Insomnie kurzfristig eingesetzt werden, wenn eine medikamentöse Behandlung ärztlich sinnvoll erscheint. Wegen Gewöhnung, Abhängigkeit und Beeinträchtigungen am Folgetag eignen sie sich in der Regel nicht als unkritische Dauerlösung.</p>')
        content = replace_para(content, r'Der Nutzen muss gegen relevante Risiken abgewogen werden', '<p>Der Nutzen muss gegen Risiken wie Benommenheit am nächsten Morgen, Gedächtnisstörungen, Stürze, ungewöhnliches Verhalten im Schlaf und eine mögliche Abhängigkeitsentwicklung abgewogen werden. Alkohol, Opioide und andere sedierende Arzneimittel können diese Risiken verstärken. Die Einnahme sollte deshalb genau nach ärztlicher Anweisung erfolgen.</p>')
        content = re.sub(r'(?is)<h([1-6])\b([^>]*)>Niedrig dosiertes Doxepin und Ramelteon</h\1>', r'<h\1\2>Weitere Medikamente und Melatonin</h\1>', content)
        content = replace_para(content, r'Doxepin in niedriger Dosierung', '<p>Je nach Ursache und Begleiterkrankungen können auch andere Wirkstoffgruppen eine Rolle spielen. Sedierende Antidepressiva werden teilweise außerhalb ihrer eigentlichen Zulassung zur Schlafunterstützung eingesetzt. Eine solche Off-Label-Anwendung erfordert eine individuelle ärztliche Nutzen-Risiko-Abwägung.</p>')
        content = replace_para(content, r'Ramelteon wirkt am Melatonin-Rezeptor', '<p>Melatonin kann bei bestimmten Störungen des Schlaf-Wach-Rhythmus sinnvoll sein. Ob ein zugelassenes Arzneimittel, ein anderes Präparat oder eine nicht medikamentöse Behandlung besser passt, hängt von Alter, Ursache der Beschwerden, Begleitmedikation und Dauer der Schlafprobleme ab.</p>')
        content = replace_para(content, r'Benzodiazepine wie Temazepam oder Triazolam', '<p>Benzodiazepine können schlaffördernd wirken, werden wegen Abhängigkeitspotenzial, Toleranz, Sturzgefahr und möglichen Entzugssymptomen jedoch sorgfältig und meist nur kurzfristig eingesetzt. Besonders bei älteren Menschen oder zusammen mit anderen dämpfenden Arzneimitteln ist eine strenge ärztliche Abwägung erforderlich.</p>')

    if pid == 6014:
        content = replace_para(content, r'Mounjaro enthält den Wirkstoff Tirzepatid', '<p>Mounjaro enthält den Wirkstoff Tirzepatid. In der Europäischen Union ist Mounjaro zur Behandlung von Typ-2-Diabetes sowie – unter festgelegten Voraussetzungen – zum Gewichtsmanagement bei Erwachsenen zugelassen. Welche Anwendung medizinisch vertretbar ist, hängt von Diagnose, BMI, Begleiterkrankungen, bisherigen Therapien und dem individuellen Risikoprofil ab.</p>')

    if pid == 6022:
        content = replace_para(content, r'Telemedizin in Deutschland unterliegt Regeln', '<p>Telemedizin in Deutschland unterliegt medizinischen und berufsrechtlichen Vorgaben. Achten Sie darauf, dass die behandelnde Person für die jeweilige Versorgung entsprechend berechtigt ist. Die Plattform sollte klar erklären, dass eine echte klinische Beurteilung durch qualifizierte medizinische Fachkräfte erfolgt und eine reine Fernbehandlung nur dann genutzt wird, wenn sie im Einzelfall ärztlich vertretbar ist.</p>')

    if pid == 6024:
        content = content.replace('Primary-Care-Anbieter', 'Hausarzt oder eine hausärztliche Praxis')
        content = content.replace('keinen festen Hausarzt oder eine hausärztliche Praxis hat', 'keine feste hausärztliche Anlaufstelle hat')

    if pid == 6026:
        content = replace_para(content, r'Suicide\s*&\s*Crisis\s*Lifeline', '<p>Bei akuter Selbstgefährdung oder unmittelbarer Gefahr wählen Sie 112. Wenn Sie in einer psychischen Krise Unterstützung brauchen, erreichen Sie die TelefonSeelsorge rund um die Uhr unter 0800 1110111, 0800 1110222 oder 116 123. Für dringende, aber nicht lebensbedrohliche medizinische Beschwerden außerhalb der üblichen Sprechzeiten ist der ärztliche Bereitschaftsdienst unter 116117 erreichbar.</p>')

    if pid == 6028:
        content = replace_para(content, r'Apotheken der Aufsicht auf Ebene der Deutschland', '<p>Prüfen Sie anschließend, welche Apotheke das Medikament tatsächlich abgibt und wo sie registriert ist. In Deutschland und der Europäischen Union gelten für Apotheken klare rechtliche Anforderungen und Aufsichtsregeln. Seriöse Anbieter machen Standort und Registrierungsangaben nachvollziehbar. Zusätzliche unabhängige Verifizierungen können hilfreich sein, ersetzen aber nicht die grundlegende Transparenz über die abgebende Apotheke.</p>')

    if pid == 6035:
        content = replace_para(content, r'Telemedizin unterliegt in Deutschland je nach Deutschland unterschiedlichen Vorgaben', '<p>Ebenso wichtig ist, dass die Plattform ihren medizinischen und rechtlichen Rahmen transparent erklärt. In Deutschland kann eine ausschließliche Fernbehandlung zulässig sein, wenn sie im konkreten Einzelfall ärztlich vertretbar ist und die erforderliche Sorgfalt gewahrt bleibt. Ist eine persönliche Untersuchung notwendig, sollte die Plattform das klar kommunizieren und keine rein digitale Behandlung versprechen.</p>')

    if pid == 6037:
        content = replace_para(content, r'Für die Gewichtsbehandlung wird Wegovy in Deutschland typischerweise', '<p>Für das Gewichtsmanagement ist Wegovy in der Europäischen Union bei Erwachsenen mit einem BMI von mindestens 30 kg/m² zugelassen. Bei einem BMI ab 27 bis unter 30 kg/m² kommt es infrage, wenn mindestens eine gewichtsbedingte Begleiterkrankung besteht. Ob die Behandlung im Einzelfall passt, hängt zusätzlich von Vorerkrankungen, aktuellen Medikamenten und weiteren Risiken ab.</p>')
        content = replace_para(content, r'medulläres Schilddrüsenkarzinom', '<p>Wegovy darf nicht angewendet werden, wenn eine Überempfindlichkeit gegen Semaglutid oder einen sonstigen Bestandteil besteht. Weitere Vorerkrankungen und mögliche Risiken müssen vor der Verordnung anhand der aktuellen EU-Fachinformation ärztlich beurteilt werden.</p>')

    if pid == 900050:
        content = replace_para(content, r'In Deutschland werden Medikamente für das Gewichtsmanagement häufig', '<p>Ob eine GLP-1-basierte Therapie geeignet ist, entscheidet eine zugelassene Ärztin oder ein zugelassener Arzt nach medizinischen Kriterien. In der Europäischen Union sind bestimmte Arzneimittel zum Gewichtsmanagement bei Erwachsenen mit einem BMI von mindestens 30 kg/m² oder ab 27 bis unter 30 kg/m² bei mindestens einer gewichtsbedingten Begleiterkrankung zugelassen. Die konkrete Eignung hängt vom jeweiligen Präparat und der individuellen gesundheitlichen Situation ab.</p>')
        content = replace_para(content, r'medullärem Schilddrüsenkarzinom|MEN-2', '<p>Eine Behandlung ist nicht für jede Person passend. Gegenanzeigen und Warnhinweise unterscheiden sich je nach Wirkstoff. Eine bekannte Überempfindlichkeit gegen den jeweiligen Wirkstoff oder einen sonstigen Bestandteil ist zu beachten; auch Schwangerschaft, Stillzeit, schwere Magen-Darm-Beschwerden, frühere Bauchspeicheldrüsenentzündungen oder Gallenprobleme müssen ärztlich eingeordnet werden. Maßgeblich sind die aktuelle EU-Fachinformation und Ihre individuelle medizinische Situation.</p>')

    if pid == 900064:
        content = replace_para(content, r'Bei akuter Gefahr oder Suizidgedanken', '<p>Wenn Schlafprobleme zusammen mit Hoffnungslosigkeit, starker Angst, deutlichen Stimmungsveränderungen oder Gedanken an Selbstverletzung auftreten, ist schnelle professionelle Hilfe wichtig. Bei akuter Selbstgefährdung oder unmittelbarer Gefahr wählen Sie 112. In einer psychischen Krise erreichen Sie die TelefonSeelsorge rund um die Uhr unter 0800 1110111, 0800 1110222 oder 116 123. Schlafmittel allein behandeln keine psychische Krise.</p>')
        content = replace_para(content, r'Giftnotruf', '<p>Bei extremer Benommenheit, flacher oder verlangsamter Atmung, Bewusstseinsstörungen oder einer vermuteten Überdosierung benötigen Sie sofort medizinische Hilfe. In Deutschland können Sie sich an den regional zuständigen Giftnotruf wenden; bei lebensbedrohlichen Symptomen, akuten Atemproblemen oder Bewusstlosigkeit wählen Sie 112.</p>')

    if pid == 6046:
        content = content.replace(
            'Verfügbarkeit und Versandzeit können jedoch von Deutschland, Arzneimittel und ärztlicher Freigabe abhängen.',
            'Verfügbarkeit und Versandzeit können jedoch von der Apotheke, dem Arzneimittel und dem Zeitpunkt der ärztlichen Freigabe abhängen.'
        )

    return fix_text_nodes(content)


BANNED = re.compile(
    r'\bin den Deutschland\b|\bje nach Deutschland\b|Ebene der Deutschland|'
    r'\bBundesstaat\w*\b|\bföderal\w*\b|Pharmacy Benefit(?: Plan)?|Primary[- ]Care(?:-Anbieter)?|'
    r'Suicide\s*&\s*Crisis\s*Lifeline|\b(?:USA|United States|Vereinigte Staaten|FDA|DEA|HIPAA|Medicare|Medicaid|Zepbound|988)\b|'
    r'\bUS-amerikan\w*\b|medullär\w*\s+Schilddrüsenkarzinom\w*|\bMEN\s*[- ]?2\b|ist-ein-online-rezept-legal-usa',
    re.I
)

plan = []
for pid in IDS:
    d = json.load(open(os.path.join(ROOT, f'{pid}.json'), encoding='utf-8'))
    post = (d.get('data') or {}).get('post') or {}
    before = post.get('content') or ''
    after = transform(pid, before)

    before_links = re.findall(r'href=["\']([^"\']+)', before, re.I)
    expected_links = [u.replace('https://doctorcura.com/ist-ein-online-rezept-legal-usa/', 'https://doctorcura.com/ist-ein-online-rezept-legal-deutschland/') for u in before_links]
    after_links = re.findall(r'href=["\']([^"\']+)', after, re.I)
    if expected_links != after_links:
        raise SystemExit(f'LINK_PREFLIGHT_FAIL {pid}')

    visible = plain(after)
    m = BANNED.search(visible)
    if m:
        raise SystemExit(f'BANNED_PREFLIGHT_FAIL {pid}: {m.group(0)}')

    for node in re.split(r'(<[^>]+>)', after)[::2]:
        if re.search(r'[ \t]+[,.!?;:]', node):
            raise SystemExit(f'PUNCTUATION_PREFLIGHT_FAIL {pid}: {node[:180]!r}')

    payload = {'id': pid, 'content': after, 'status': post.get('status', 'publish')}
    json.dump(payload, open(os.path.join(OUT, f'{pid}.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    plan.append({'id': pid, 'changed': after != before, 'links': len(after_links)})

json.dump(plan, open(os.path.join(OUT, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
with open(os.path.join(OUT, 'changed.txt'), 'w', encoding='utf-8') as f:
    for row in plan:
        if row['changed']:
            f.write(str(row['id']) + '\n')
print('EU_CLEANUP_PREFLIGHT_OK', len(IDS), 'changed', sum(1 for x in plan if x['changed']))
