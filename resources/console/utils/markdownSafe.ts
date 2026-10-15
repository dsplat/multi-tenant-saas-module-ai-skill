/**
 * Skill 正文 Markdown 安全渲染。
 * 禁原始 HTML 与危险链接；不从文件目录补齐内容。
 */

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function renderInline(text: string): string {
  let out = text
  out = out.replace(/`([^`\n]+)`/g, (_m, code: string) => `<code>${code}</code>`)
  out = out.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (_m, label: string, url: string) => {
    if (/^https?:\/\//i.test(url)) {
      return `<a href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>`
    }
    // 站内相对路径与其它协议一律不生成可点击链接
    return label
  })
  out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
  return out
}

/** 渲染为受控 HTML：只输出 p/h/pre/code/ul/ol/li/a/strong/br */
export function renderSkillMarkdown(raw: string | null | undefined): string {
  if (!raw) return ''
  const escaped = escapeHtml(raw)
  const lines = escaped.split('\n')
  const html: string[] = []
  let listTag: 'ul' | 'ol' | null = null
  let inCodeBlock = false
  const codeLines: string[] = []

  const closeList = () => {
    if (listTag) {
      html.push(`</${listTag}>`)
      listTag = null
    }
  }

  for (const line of lines) {
    if (/^```/.test(line.trim())) {
      if (inCodeBlock) {
        html.push(`<pre>${codeLines.join('\n')}</pre>`)
        codeLines.length = 0
        inCodeBlock = false
      } else {
        closeList()
        inCodeBlock = true
      }
      continue
    }
    if (inCodeBlock) {
      codeLines.push(line)
      continue
    }
    const heading = line.match(/^(#{1,6})\s+(.*)$/)
    if (heading) {
      closeList()
      const level = heading[1].length
      html.push(`<h${level}>${renderInline(heading[2])}</h${level}>`)
      continue
    }
    const ul = line.match(/^\s*[-*]\s+(.*)$/)
    if (ul) {
      if (listTag !== 'ul') {
        closeList()
        html.push('<ul>')
        listTag = 'ul'
      }
      html.push(`<li>${renderInline(ul[1])}</li>`)
      continue
    }
    const ol = line.match(/^\s*\d+\.\s+(.*)$/)
    if (ol) {
      if (listTag !== 'ol') {
        closeList()
        html.push('<ol>')
        listTag = 'ol'
      }
      html.push(`<li>${renderInline(ol[1])}</li>`)
      continue
    }
    closeList()
    if (line.trim() === '') continue
    html.push(`<p>${renderInline(line)}</p>`)
  }
  if (inCodeBlock && codeLines.length) {
    html.push(`<pre>${codeLines.join('\n')}</pre>`)
  }
  closeList()
  return html.join('\n')
}
