import { useState } from 'react';
import type { StockNode } from '@/shared/api/warehouses';

type ItemProps = {
  node: StockNode;
  depth: number;
  selectedId: number | null;
  onSelect: (node: StockNode) => void;
};

function StockTreeItem({ node, depth, selectedId, onSelect }: ItemProps) {
  const [open, setOpen] = useState(depth < 2);
  const children = node.children || [];
  const hasChildren = children.length > 0;
  const selected = selectedId === node.id;

  return (
    <li className="mf-tree-item">
      <div
        className={`mf-tree-row${selected ? ' mf-tree-row-selected' : ''}`}
        style={{ paddingLeft: `${depth * 1.1}rem` }}
      >
        {hasChildren ? (
          <button
            type="button"
            className="mf-tree-toggle"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
          >
            <i className={`bi bi-chevron-${open ? 'down' : 'right'}`} aria-hidden="true" />
          </button>
        ) : (
          <span className="mf-tree-toggle mf-tree-toggle-spacer" />
        )}
        <button type="button" className="mf-tree-select" onClick={() => onSelect(node)}>
          <span className="mf-tree-type">{node.type || '—'}</span>
          <strong>{node.name || `Узел #${node.id}`}</strong>
          {node.code ? <code className="mf-tree-code">{String(node.code)}</code> : null}
          <span className="mf-muted small">#{node.id}</span>
        </button>
      </div>
      {hasChildren && open ? (
        <ul className="mf-tree-children">
          {children.map((child) => (
            <StockTreeItem
              key={child.id}
              node={child}
              depth={depth + 1}
              selectedId={selectedId}
              onSelect={onSelect}
            />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

type Props = {
  nodes: StockNode[];
  selectedId: number | null;
  onSelect: (node: StockNode) => void;
};

export function StockTree({ nodes, selectedId, onSelect }: Props) {
  if (nodes.length === 0) {
    return <p className="mf-muted mb-0">Дерево пусто. Создайте корневой узел через API или форму ниже.</p>;
  }
  return (
    <ul className="mf-tree-root">
      {nodes.map((node) => (
        <StockTreeItem
          key={node.id}
          node={node}
          depth={0}
          selectedId={selectedId}
          onSelect={onSelect}
        />
      ))}
    </ul>
  );
}
