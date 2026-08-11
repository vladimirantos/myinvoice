// Mini markdown renderer (nadpisy, seznamy, odstavce, inline code/bold/italic,
// odkazy, code fence). Žádný HTML injection — escape všechno, pak inline tagy + bloky.
// Původně lokální v admin/Update.vue (release notes), vytaženo pro Poznámky.

export function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

export function renderMarkdown(md: string): string {
  if (!md) return ''
  const lines = md.replace(/\r\n/g, '\n').split('\n')
  const out: string[] = []
  let listType: 'ul' | 'ol' | null = null
  let para: string[] = []
  let li: string[] | null = null
  let inFence = false
  let fenceBuf: string[] = []

  const flushPara = () => {
    if (para.length) {
      out.push('<p>' + inline(para.join(' ')) + '</p>')
      para = []
    }
  }
  // Odrážka se sbírá po řádcích: pokračovací řádky (zalomený text odstavce
  // pod `- `) patří pořád do téže položky, ne do samostatného odstavce.
  const flushLi = () => {
    if (li) {
      out.push('<li>' + inline(li.join(' ')) + '</li>')
      li = null
    }
  }
  const closeList = () => {
    flushLi()
    if (listType) {
      out.push(`</${listType}>`)
      listType = null
    }
  }
  const ensureList = (t: 'ul' | 'ol') => {
    if (listType !== t) {
      closeList()
      out.push(`<${t}>`)
      listType = t
    }
  }

  function inline(s: string): string {
    let r = escapeHtml(s)
    r = r.replace(/`([^`]+)`/g, '<code>$1</code>')
    r = r.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    r = r.replace(/(?<!\w)\*([^*\n]+)\*(?!\w)/g, '<em>$1</em>')
    // URL je už HTML-escapovaná (inline() escapuje celý řetězec výše). Povol jen bezpečná
    // schémata — `javascript:`/`data:` apod. zahoď a vykresli jen text (XSS guard).
    r = r.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, text: string, url: string) =>
      /^(https?:\/\/|mailto:|\/|#)/i.test(url)
        ? `<a href="${url}" target="_blank" rel="noopener">${text}</a>`
        : text,
    )
    return r
  }

  for (const line of lines) {
    if (/^```/.test(line.trim())) {
      if (!inFence) {
        flushPara()
        closeList()
        inFence = true
        fenceBuf = []
      } else {
        out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
        inFence = false
      }
      continue
    }
    if (inFence) {
      fenceBuf.push(line)
      continue
    }

    const trim = line.trim()
    if (trim === '') {
      flushPara()
      closeList()
      continue
    }
    const heading = trim.match(/^(#{1,6})\s+(.+)$/)
    if (heading) {
      flushPara()
      closeList()
      const lvl = heading[1].length
      out.push(`<h${lvl}>${inline(heading[2])}</h${lvl}>`)
      continue
    }
    if (/^[-*]\s+/.test(trim)) {
      flushPara()
      flushLi()
      ensureList('ul')
      li = [trim.replace(/^[-*]\s+/, '')]
      continue
    }
    if (/^\d+\.\s+/.test(trim)) {
      flushPara()
      flushLi()
      ensureList('ol')
      li = [trim.replace(/^\d+\.\s+/, '')]
      continue
    }
    if (li) {
      li.push(trim)
      continue
    }
    closeList()
    para.push(trim)
  }
  flushPara()
  closeList()
  if (inFence) {
    out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
  }
  return out.join('\n')
}
