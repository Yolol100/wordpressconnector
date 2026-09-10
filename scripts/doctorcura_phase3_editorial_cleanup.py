#!/usr/bin/env python3
import html
import json
import os
import re
import sys

ROOT = sys.argv[1]
IDS = [int(x) for x in sys.argv[2:]]

TITLE = {
    900050: 'GLP-1-Therapie für Einsteiger: Was vor dem Start zählt',
    6037: 'Wegovy-Behandlung richtig anfragen in 5 Schritten',
    6001: 'Schlafmittel online auf Rezept anfragen',
    5988: 'Mounjaro-Rezept online ärztlich anfragen',
    5984: 'Wann wird ein Online-Rezept abgelehnt – und warum?',
    5926: 'Wie schnell kommen rezeptpflichtige Medikamente nach Hause?',
    5902: 'Unterschied zwischen Mounjaro und Wegovy erklärt',
    5866: 'Wegovy-Rezept online beantragen – so geht es',
}

EXCERPT = {
    900050: 'GLP-1-Therapie für Einsteiger: So laufen ärztliche Prüfung, Behandlungsbeginn und medizinische Begleitung beim Gewichtsmanagement ab.',
    6037: 'Wegovy-Behandlung richtig anfragen: Voraussetzungen, relevante Gesundheitsangaben und Ablauf der ärztlichen Online-Prüfung.',
    6001: 'Schlafmittel online auf Rezept anfragen: So läuft die ärztliche Prüfung ab, welche Risiken zählen und wann eine Behandlung infrage kommen kann.',
    5997: 'Potenzmittel online auf Rezept anfragen: So laufen ärztliche Prüfung, mögliche Verordnung und Arzneimittelversand ab.',
    5988: 'Mounjaro-Rezept online anfragen: Ablauf, ärztliche Prüfung, mögliche Risiken, Kosten und wichtige Voraussetzungen für die Behandlung.',
    5984: 'Wann wird ein Online-Rezept abgelehnt? Lesen Sie, welche medizinischen und rechtlichen Gründe eine Rolle spielen und welche Angaben für die ärztliche Prüfung wichtig sind.',
    5926: 'Wie schnell rezeptpflichtige Medikamente geliefert werden können, hängt von ärztlicher Prüfung, Verfügbarkeit in der Apotheke und Versand ab.',
    5902: 'Unterschied zwischen Mounjaro und Wegovy: Wirkstoffe, Anwendung, Nebenwirkungen und wichtige Kriterien für die ärztliche Auswahl.',
    5866: 'Wegovy-Rezept online beantragen: So läuft die ärztliche Prüfung ab, welche Angaben wichtig sind und wann eine Behandlung infrage kommen kann.',
}

OLD_VISIBLE = {
    900050: r'\bGLP1\b',
    6037: r'\bWegovy Behandlung\b',
    6035: r'\bin meinem Deutschland\b',
    6018: r'\bDie beste\s+online\s+Behandlungen\b',
    6001: r'\bSchlafmittel online Rezept\b',
    5997: r'\bpotenzmittel online rezept\b',
    5988: r'\bMounjaro Rezept\b',
    5984: r'\bonline rezept\b',
    5926: r'\bRezeptmedizin\b',
    5920: r'\bgewichtstherapie mit glp1 medikamenten\b',
    5902: r'\bmounjaro oder wegovy unterschied\b',
    5900: r'consultation included|doctor review|shipping included',
    5866: r'\bWegovy Rezept\b',
}


def text_nodes_transform(content, fn):
    parts = re.split(r'(<[^>]+>)', content)
    changed = 0
    for i in range(0, len(parts), 2):
        after, n = fn(parts[i])
        parts[i] = after
        changed += n
    return ''.join(parts), changed


def regex_replace(s, pattern, replacement, flags=re.I):
    return re.subn(pattern, replacement, s, flags=flags)


def visible_text(content):
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'(?s)<[^>]+>', ' ', content or ''))).strip()


def transform_content(pid, content):
    total = 0

    if pid == 900050:
        def fn(s):
            a, n1 = re.subn(r'\bGLP1\b', 'GLP-1', s)
            a, n2 = re.subn(r'\bglp1\b', 'GLP-1', a)
            return a, n1 + n2
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 6037:
        def fn(s): return regex_replace(s, r'\bWegovy Behandlung\b', 'Wegovy-Behandlung')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 6035:
        old = 'Ist die Behandlung in meinem Deutschland verfügbar?'
        new = 'Ist die Behandlung an meinem Aufenthaltsort verfügbar?'
        def fn(s): return (s.replace(old, new), s.count(old))
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 6018:
        content, n = re.subn(
            r'Die beste(?:\s|<[^>]+>)*online(?:\s|<[^>]+>)*Behandlungen',
            'Die besten Online-Behandlungen',
            content,
            flags=re.I,
        )
        total += n

    elif pid == 6001:
        def fn(s): return regex_replace(s, r'\bSchlafmittel online Rezept\b', 'Schlafmittel online auf Rezept')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5997:
        def fn(s): return regex_replace(s, r'\bpotenzmittel online rezept\b', 'Potenzmittel online auf Rezept')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5988:
        def fn(s): return regex_replace(s, r'\bMounjaro Rezept\b', 'Mounjaro-Rezept')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5984:
        def fn(s): return regex_replace(s, r'\bonline rezept\b', 'Online-Rezept')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5926:
        def fn(s):
            a, n1 = re.subn(r'\bRezeptmedizin\b', 'rezeptpflichtige Medikamente', s, flags=re.I)
            a, n2 = re.subn(r'\bkommt\s+(rezeptpflichtige Medikamente)\b', r'kommen \1', a, flags=re.I)
            a, n3 = re.subn(r'\b(rezeptpflichtige Medikamente)\s+nach Hause kommt\b', r'\1 nach Hause kommen', a, flags=re.I)
            return a, n1 + n2 + n3
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5920:
        def fn(s): return regex_replace(s, r'\bgewichtstherapie mit glp1 medikamenten\b', 'Gewichtstherapie mit GLP-1-Medikamenten')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5902:
        def fn(s): return regex_replace(s, r'\bmounjaro oder wegovy unterschied\b', 'Unterschied zwischen Mounjaro und Wegovy')
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5900:
        old = 'Begriffe wie consultation included, doctor review oder shipping included klingen ähnlich, meinen aber nicht immer dasselbe.'
        new = 'Begriffe wie „inklusive Sprechstunde“, „ärztliche Prüfung“ oder „Versand inklusive“ klingen ähnlich, meinen aber nicht immer dasselbe.'
        def fn(s): return (s.replace(old, new), s.count(old))
        content, n = text_nodes_transform(content, fn); total += n

    elif pid == 5866:
        def fn(s): return regex_replace(s, r'\bWegovy Rezept\b', 'Wegovy-Rezept')
        content, n = text_nodes_transform(content, fn); total += n

    else:
        raise SystemExit(f'UNSUPPORTED_ID {pid}')

    residue = re.search(OLD_VISIBLE[pid], visible_text(content), re.I)
    if residue:
        raise SystemExit(f'CONTENT_RESIDUAL {pid}: {residue.group(0)}')
    return content, total


def yoast_payload(pid, before_post, before_yoast, new_title, new_excerpt):
    fields = (before_yoast.get('data') or {}).get('fields') or {}
    updates = {}
    old_title = str(before_post.get('title') or '')
    if new_title is not None:
        for key in ('title', 'opengraph_title', 'twitter_title'):
            value = fields.get(key)
            if isinstance(value, str) and value:
                updates[key] = value.replace(old_title, new_title) if old_title in value else value
    if new_excerpt is not None:
        if isinstance(fields.get('description'), str) and fields.get('description'):
            updates['description'] = new_excerpt
        for key in ('opengraph_description', 'twitter_description'):
            if isinstance(fields.get(key), str) and fields.get(key):
                updates[key] = new_excerpt
    return {'id': pid, 'fields': updates}


os.makedirs(os.path.join(ROOT, 'p'), exist_ok=True)
os.makedirs(os.path.join(ROOT, 'y'), exist_ok=True)
expected = {}

for pid in IDS:
    before_doc = json.load(open(os.path.join(ROOT, 'before', f'{pid}.json'), encoding='utf-8'))
    post = (before_doc.get('data') or {}).get('post') or {}
    before_y = json.load(open(os.path.join(ROOT, 'yb', f'{pid}.json'), encoding='utf-8'))
    old_content = post.get('content') or ''
    old_links = re.findall(r'href=["\']([^"\']+)', old_content, re.I)

    new_content, replacements = transform_content(pid, old_content)
    new_links = re.findall(r'href=["\']([^"\']+)', new_content, re.I)
    if old_links != new_links:
        raise SystemExit(f'LINK_PREFLIGHT_FAIL {pid}')

    new_title = TITLE.get(pid)
    new_excerpt = EXCERPT.get(pid)
    if new_title is not None and not str(post.get('title') or '').strip():
        raise SystemExit(f'MISSING_TITLE {pid}')

    payload = {
        'id': pid,
        'title': new_title if new_title is not None else post.get('title'),
        'excerpt': new_excerpt if new_excerpt is not None else post.get('excerpt'),
        'content': new_content,
        'status': post.get('status', 'publish'),
    }
    json.dump(payload, open(os.path.join(ROOT, 'p', f'{pid}.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))

    yp = yoast_payload(pid, post, before_y, new_title, new_excerpt)
    json.dump(yp, open(os.path.join(ROOT, 'y', f'{pid}.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))

    expected[str(pid)] = {
        'old_title': post.get('title'),
        'new_title': payload['title'],
        'new_excerpt': payload['excerpt'],
        'status': payload['status'],
        'links': old_links,
        'content': new_content,
        'yoast_fields': yp['fields'],
        'replacements': replacements,
    }

json.dump(expected, open(os.path.join(ROOT, 'expected.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
print('PHASE3_LOCAL_PREFLIGHT_OK ids='+','.join(map(str, IDS))+' replacements='+str(sum(x['replacements'] for x in expected.values())))
