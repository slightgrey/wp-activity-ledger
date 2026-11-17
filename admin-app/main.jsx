import { createRoot } from 'react-dom/client';
import './index.css';

function Placeholder() {
  return <div className="p-6 text-foreground">WP Activity Ledger</div>;
}

const root = document.getElementById('wpal-root');

if (root) {
  createRoot(root).render(<Placeholder />);
}
