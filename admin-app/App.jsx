import { Suspense, lazy, useEffect } from 'react';
import { HashRouter, NavLink, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { config } from '@/lib/config';
import { cn } from '@/lib/utils';
import { Toaster } from '@/components/ui/toast';
import { Skeleton } from '@/components/ui/skeleton';

// Screens load on first visit (the dashboard's chart library is the largest part).
const Dashboard = lazy(() => import('@/pages/Dashboard'));
const ActivityLog = lazy(() => import('@/pages/ActivityLog'));
const Users = lazy(() => import('@/pages/Users'));
const UserDetail = lazy(() => import('@/pages/UserDetail'));
const Alerts = lazy(() => import('@/pages/Alerts'));
const Settings = lazy(() => import('@/pages/Settings'));
const Gallery = lazy(() => import('@/pages/Gallery'));

const SCREENS = [
  { path: '/', label: 'Dashboard', cap: 'view', element: <Dashboard /> },
  { path: '/log', label: 'Activity Log', cap: 'view', element: <ActivityLog /> },
  { path: '/users', label: 'Users & Sessions', cap: 'view', element: <Users /> },
  { path: '/alerts', label: 'Alerts', cap: 'manage_settings', element: <Alerts /> },
  { path: '/settings', label: 'Settings', cap: 'manage_settings', element: <Settings /> },
];

const DEV = import.meta.env.MODE === 'development';

/**
 * Marks the wp-admin submenu item of the current screen as current.
 *
 * @param {string} pathname Current route.
 */
function syncAdminMenu(pathname) {
  const items = document.querySelectorAll('#toplevel_page_wp-activity-ledger .wp-submenu li');

  items.forEach((li) => {
    const link = li.querySelector('a');
    const hash = link?.getAttribute('href')?.split('#')[1] ?? '/';
    const section = '/' + (pathname.split('/')[1] ?? '');
    const current = link && (hash === pathname || (hash !== '/' && hash === section));

    li.classList.toggle('current', Boolean(current));
    link?.classList.toggle('current', Boolean(current));
  });
}

function Layout() {
  const location = useLocation();
  const screens = SCREENS.filter((screen) => config.caps[screen.cap]);

  useEffect(() => syncAdminMenu(location.pathname), [location.pathname]);

  return (
    <div className="space-y-4 pr-4">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-xl font-semibold">Activity Ledger</h2>
        <nav className="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
          {screens.map((screen) => (
            <NavLink
              key={screen.path}
              to={screen.path}
              end={screen.path === '/'}
              className={({ isActive }) =>
                cn(
                  'rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground',
                  isActive && 'bg-background text-foreground shadow-sm'
                )
              }
            >
              {screen.label}
            </NavLink>
          ))}
        </nav>
      </header>
      <main>
        <Suspense fallback={<Skeleton className="h-64 w-full" />}>
          <Routes>
            {screens.map((screen) => (
              <Route key={screen.path} path={screen.path} element={screen.element} />
            ))}
            {config.caps.view && <Route path="/users/:id" element={<UserDetail />} />}
            {DEV && <Route path="/dev" element={<Gallery />} />}
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </main>
    </div>
  );
}

export default function App() {
  return (
    <HashRouter>
      <Toaster>
        <Layout />
      </Toaster>
      <div id="wpal-portal" />
    </HashRouter>
  );
}
