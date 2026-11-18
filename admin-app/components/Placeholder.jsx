import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { __ } from '@/lib/i18n';

/**
 * A screen that is not built yet.
 */
export default function Placeholder({ title, children }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{__( 'This screen is coming soon.', 'wp-activity-ledger' )}</CardDescription>
      </CardHeader>
      {children && <CardContent>{children}</CardContent>}
    </Card>
  );
}
