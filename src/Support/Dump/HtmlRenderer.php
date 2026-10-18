<?php

declare(strict_types=1);

namespace Haki\Support\Dump;

/**
 * Renderiza nós como um bloco HTML com tipos coloridos e itens recolhíveis.
 */
final class HtmlRenderer
{
    /** Níveis abertos por padrão; os mais profundos começam recolhidos. */
    private const OPEN_LEVELS = 2;

    private const VISIBILITY = [
        'public' => ['+', 'público'],
        'protected' => ['#', 'protegido'],
        'private' => ['-', 'privado'],
    ];

    /**
     * @param list<Node> $nodes
     */
    public function render(array $nodes, ?string $file, ?int $line, bool $withAssets = true): string
    {
        $html = $withAssets ? $this->assets() : '';
        $html .= '<div class="hk-dump">';
        $html .= $this->header($file, $line);
        $html .= '<div class="hk-body">';

        if ($nodes === []) {
            $html .= '<div class="hk-empty">nenhum valor informado</div>';
        }

        foreach ($nodes as $index => $node) {
            $html .= '<div class="hk-value">';

            if (count($nodes) > 1) {
                $html .= '<span class="hk-index">#' . ($index + 1) . '</span>';
            }

            $html .= $this->node($node, null, 0) . '</div>';
        }

        return $html . '</div></div>';
    }

    private function header(?string $file, ?int $line): string
    {
        $location = '';

        if ($file !== null) {
            $text = basename($file) . ($line !== null ? ':' . $line : '');
            $location = '<span class="hk-loc" title="' . $this->e($file) . '">' . $this->e($text) . '</span>';
        }

        return '<div class="hk-head">'
            . '<span class="hk-logo">haki</span>' . $location
            . '<span class="hk-actions">'
            . '<button type="button" data-hk="open" title="Expandir tudo">expandir</button>'
            . '<button type="button" data-hk="close" title="Recolher tudo">recolher</button>'
            . '</span></div>';
    }

    private function node(Node $node, ?Child $child, int $depth): string
    {
        $key = $child !== null ? $this->key($child) : '';

        if (!$node->isContainer() || !$node->hasChildren()) {
            return '<div class="hk-row">' . $key . $this->summary($node) . '</div>';
        }

        $open = $depth < self::OPEN_LEVELS ? ' open' : '';
        $html = '<details' . $open . '><summary>' . $key . $this->summary($node) . '</summary><div class="hk-children">';

        foreach ($node->children as $item) {
            $html .= $this->node($item->node, $item, $depth + 1);
        }

        if ($node->hidden > 0) {
            $html .= '<div class="hk-row hk-note">… e mais ' . $node->hidden . ' itens</div>';
        }

        if ($node->truncated) {
            $html .= '<div class="hk-row hk-note">… profundidade máxima atingida</div>';
        }

        return $html . '</div></details>';
    }

    private function key(Child $child): string
    {
        if ($child->kind === 'index') {
            return '<span class="hk-key hk-key-index">' . $this->e($child->key) . '</span><span class="hk-arrow">⇒</span>';
        }

        if ($child->kind === 'key') {
            return '<span class="hk-key hk-key-string">"' . $this->e($child->key) . '"</span><span class="hk-arrow">⇒</span>';
        }

        [$symbol, $label] = self::VISIBILITY[$child->kind];
        $title = $label . ($child->owner !== null ? ' (declarado em ' . $child->owner . ')' : '');

        return '<span class="hk-vis hk-vis-' . $child->kind . '" title="' . $this->e($title) . '">' . $symbol . '</span>'
            . '<span class="hk-key hk-key-prop">' . $this->e($child->key) . '</span><span class="hk-arrow">:</span>';
    }

    private function summary(Node $node): string
    {
        $badge = fn (string $text): string => '<span class="hk-badge hk-t-' . $node->type . '">' . $this->e($text) . '</span>';
        $meta = fn (?string $text): string => $text !== null ? '<span class="hk-meta">' . $this->e($text) . '</span>' : '';

        return match ($node->type) {
            'null' => $badge('null'),
            'bool', 'int', 'float' => $badge($node->type) . '<span class="hk-v hk-v-' . $node->type . '">' . $this->e($node->value) . '</span>',
            'string' => $node->tag === 'binário'
                ? $badge('string') . '<span class="hk-v hk-v-binary">' . $this->e($node->value) . '</span>' . $meta('binário · ' . $node->meta)
                : $badge('string') . '<span class="hk-v hk-v-string">"' . $this->e($node->value) . '"</span>' . $meta($this->plural((int) $node->meta, 'caractere', 'caracteres')),
            'array' => $badge('array') . $meta(
                $node->meta === '0'
                    ? 'vazio'
                    : $this->plural((int) $node->meta, 'item', 'itens') . ' · ' . $node->tag
            ),
            'object' => $badge('object') . '<span class="hk-class">' . $this->e($node->value) . '</span>'
                . '<span class="hk-id">' . $this->e((string) $node->meta) . '</span>'
                . ($node->tag !== null ? '<span class="hk-v hk-v-date">' . $this->e($node->tag) . '</span>' : ''),
            'enum' => $badge('enum') . '<span class="hk-v hk-v-enum">' . $this->e($node->value) . '</span>' . $meta($node->meta !== null ? '= ' . $node->meta : null),
            'closure' => $badge('closure') . '<span class="hk-v hk-v-closure">' . $this->e($node->value) . '</span>' . $meta($node->meta),
            'resource' => $badge('resource') . '<span class="hk-v">' . $this->e($node->value) . '</span>' . $meta($node->meta),
            'recursion' => $badge('recursão') . '<span class="hk-class">' . $this->e($node->value) . '</span>'
                . '<span class="hk-id">' . $this->e((string) $node->meta) . '</span>' . $meta('referência circular'),
            default => $badge($node->type) . $this->e($node->value),
        };
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function assets(): string
    {
        return <<<'HTML'
<style>
.hk-dump{--hk-bg:#0f1117;--hk-panel:#161a23;--hk-line:#262c3a;--hk-text:#d6dbe6;--hk-muted:#7d869c;
  --hk-null:#8b93a7;--hk-bool:#c792ea;--hk-num:#7cb7ff;--hk-str:#9ddb8a;--hk-arr:#ffb86b;--hk-obj:#ff7a90;
  --hk-enum:#5fd7d7;--hk-fn:#f2cc60;--hk-red:#e5484d;
  font:13px/1.6 ui-monospace,SFMono-Regular,"JetBrains Mono",Menlo,Consolas,monospace;color:var(--hk-text);
  background:var(--hk-bg);border:1px solid var(--hk-line);border-left:3px solid var(--hk-red);border-radius:8px;
  margin:12px 0;overflow:hidden;text-align:left;box-shadow:0 4px 18px rgba(0,0,0,.25)}
.hk-dump *{box-sizing:border-box}
.hk-head{display:flex;align-items:center;gap:10px;padding:6px 12px;background:var(--hk-panel);border-bottom:1px solid var(--hk-line)}
.hk-logo{font-weight:700;color:#fff;background:var(--hk-red);padding:0 8px;border-radius:4px;letter-spacing:.5px}
.hk-loc{color:var(--hk-muted);cursor:help}
.hk-actions{margin-left:auto;display:flex;gap:6px}
.hk-actions button{font:inherit;font-size:11px;color:var(--hk-muted);background:transparent;border:1px solid var(--hk-line);
  border-radius:4px;padding:0 8px;cursor:pointer}
.hk-actions button:hover{color:var(--hk-text);border-color:var(--hk-muted)}
.hk-body{padding:8px 12px;overflow-x:auto}
.hk-value+.hk-value{border-top:1px dashed var(--hk-line);margin-top:6px;padding-top:6px}
.hk-index{color:var(--hk-muted);margin-right:6px}
.hk-empty,.hk-note{color:var(--hk-muted);font-style:italic}
.hk-dump details>summary{list-style:none;cursor:pointer;border-radius:4px;padding:0 4px}
.hk-dump details>summary::-webkit-details-marker{display:none}
.hk-dump details>summary::before{content:"▸";display:inline-block;width:14px;color:var(--hk-muted);transition:transform .12s}
.hk-dump details[open]>summary::before{transform:rotate(90deg)}
.hk-dump summary:hover,.hk-row:hover{background:rgba(255,255,255,.04)}
.hk-row{padding:0 4px 0 18px;border-radius:4px}
.hk-children{margin-left:10px;padding-left:8px;border-left:1px solid var(--hk-line)}
.hk-value>.hk-row,.hk-value>details{display:inline-block;vertical-align:top}
.hk-badge{display:inline-block;font-size:11px;line-height:17px;padding:0 6px;border-radius:4px;margin-right:6px;
  border:1px solid currentColor;background:color-mix(in srgb,currentColor 14%,transparent)}
.hk-t-null{color:var(--hk-null)}.hk-t-bool{color:var(--hk-bool)}.hk-t-int,.hk-t-float{color:var(--hk-num)}
.hk-t-string{color:var(--hk-str)}.hk-t-array{color:var(--hk-arr)}.hk-t-object{color:var(--hk-obj)}
.hk-t-enum{color:var(--hk-enum)}.hk-t-closure{color:var(--hk-fn)}.hk-t-resource{color:var(--hk-muted)}
.hk-t-recursion{color:var(--hk-red)}
.hk-v-bool{color:var(--hk-bool)}.hk-v-int,.hk-v-float{color:var(--hk-num)}
.hk-v-string{color:var(--hk-str);white-space:pre-wrap;word-break:break-word}
.hk-v-binary{color:var(--hk-muted)}.hk-v-enum{color:var(--hk-enum)}.hk-v-closure{color:var(--hk-fn)}
.hk-v-date{color:var(--hk-num);margin-left:8px}
.hk-meta{color:var(--hk-muted);margin-left:8px;font-size:12px}
.hk-class{color:var(--hk-obj);font-weight:600}
.hk-id{color:var(--hk-muted);margin-left:6px;font-size:12px}
.hk-key{margin-right:2px}.hk-key-index{color:var(--hk-num)}.hk-key-string{color:var(--hk-str)}.hk-key-prop{color:var(--hk-text)}
.hk-arrow{color:var(--hk-muted);margin:0 6px}
.hk-vis{display:inline-block;width:12px;font-weight:700;cursor:help}
.hk-vis-public{color:#4cc38a}.hk-vis-protected{color:#f2cc60}.hk-vis-private{color:var(--hk-red)}
</style>
<script>
(function(){
  if (window.__hakiDump) return; window.__hakiDump = true;
  document.addEventListener('click', function (e) {
    var button = e.target.closest && e.target.closest('.hk-dump [data-hk]');
    if (button) {
      var open = button.getAttribute('data-hk') === 'open';
      button.closest('.hk-dump').querySelectorAll('details').forEach(function (d) { d.open = open; });
      return;
    }
    var summary = e.target.closest && e.target.closest('.hk-dump summary');
    if (summary && (e.ctrlKey || e.metaKey || e.altKey)) {
      e.preventDefault();
      var details = summary.parentElement, open = !details.open;
      details.open = open;
      details.querySelectorAll('details').forEach(function (d) { d.open = open; });
    }
  });
})();
</script>
HTML;
    }
}
