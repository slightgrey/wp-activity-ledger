import { Suspense, lazy, useEffect } from 'react';
import { HashRouter, NavLink, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { config } from '@/lib/config';
import { Bell, BookOpenCheck, LayoutDashboard, Network, ScrollText, Settings as SettingsIcon, Users as UsersIcon } from 'lucide-react';
import { segmentClass, segmentGroupClass } from '@/lib/utils';
import { Toaster } from '@/components/ui/toast';
import { Skeleton } from '@/components/ui/skeleton';
import SiteSwitcher from '@/components/SiteSwitcher';
import { __ } from '@/lib/i18n';

// Screens load on first visit (the dashboard's chart library is the largest part).
const Dashboard = lazy(() => import('@/pages/Dashboard'));
const ActivityLog = lazy(() => import('@/pages/ActivityLog'));
const Users = lazy(() => import('@/pages/Users'));
const UserDetail = lazy(() => import('@/pages/UserDetail'));
const Alerts = lazy(() => import('@/pages/Alerts'));
const Settings = lazy(() => import('@/pages/Settings'));
const Gallery = lazy(() => import('@/pages/Gallery'));
const NetworkSettings = lazy(() => import('@/pages/NetworkSettings'));

const SITE_SCREENS = [
  { path: '/', label: __( 'Dashboard', 'wp-activity-ledger' ), icon: LayoutDashboard, cap: 'view', element: <Dashboard /> },
  { path: '/log', label: __( 'Activity Log', 'wp-activity-ledger' ), icon: ScrollText, cap: 'view', element: <ActivityLog /> },
  { path: '/users', label: __( 'Users & Sessions', 'wp-activity-ledger' ), icon: UsersIcon, cap: 'view', element: <Users /> },
  { path: '/alerts', label: __( 'Alerts', 'wp-activity-ledger' ), icon: Bell, cap: 'manage_settings', element: <Alerts /> },
  // Shown read-only to site admins while the network lock is on.
  { path: '/settings', label: __( 'Settings', 'wp-activity-ledger' ), icon: SettingsIcon, cap: config.settingsLocked ? 'manage_options' : 'manage_settings', element: <Settings /> },
];

const NETWORK_SCREENS = [
  { path: '/', label: __( 'Dashboard', 'wp-activity-ledger' ), icon: LayoutDashboard, cap: 'view', element: <Dashboard /> },
  { path: '/log', label: __( 'Activity Log', 'wp-activity-ledger' ), icon: ScrollText, cap: 'view', element: <ActivityLog /> },
  { path: '/users', label: __( 'Users & Sessions', 'wp-activity-ledger' ), icon: UsersIcon, cap: 'view', element: <Users /> },
  { path: '/network', label: __( 'Network Settings', 'wp-activity-ledger' ), icon: Network, cap: 'view', element: <NetworkSettings /> },
];

const SCREENS = config.network ? NETWORK_SCREENS : SITE_SCREENS;

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
        <div className="flex flex-wrap items-center gap-3">
          <h2 className="flex items-center gap-2 text-xl font-semibold">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-sm">
              <BookOpenCheck className="h-4 w-4" aria-hidden="true" />
            </span>
            {__( 'Activity Ledger', 'wp-activity-ledger' )}{config.network ? __( ' · Network', 'wp-activity-ledger' ) : ''}
          </h2>
          {config.network && location.pathname !== '/network' && <SiteSwitcher />}
        </div>
        <nav className={segmentGroupClass}>
          {screens.map((screen) => (
            <NavLink key={screen.path} to={screen.path} end={screen.path === '/'} className={({ isActive }) => segmentClass(isActive)}>
              <screen.icon className="h-4 w-4" aria-hidden="true" />
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
