import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Check, CheckCircle2, Coffee, ExternalLink, Mail, MessageSquarePlus, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import FieldError from '@/components/FieldError';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { parseAmount, paypalUrl } from '@/lib/donate';
import { __, sprintf } from '@/lib/i18n';

const EMPTY = { name: config.user?.name ?? '', email: config.user?.email ?? '', plugin: '', plugin_url: '', message: '' };

/**
 * Builds a mailto: link with the request, for when the site can't send email.
 *
 * @param {object} form Form values.
 * @returns {string} URL.
 */
function mailtoLink(form) {
  const subject = sprintf( __( '[Activity Ledger] Integration request: %s', 'wp-activity-ledger' ), form.plugin );
  const body = [`Plugin: ${form.plugin}`, `Plugin link: ${form.plugin_url || '-'}`, '', form.message || '-', '', `Activity Ledger: ${config.version}`].join('\n');

  return `mailto:${config.author.requestEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

/**
 * One labelled form field.
 */
function Field({ id, label, hint, errors, field, children }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>
        {label}
        {hint && <span className="ml-1 font-normal text-muted-foreground">{hint}</span>}
      </Label>
      {children}
      <FieldError errors={errors} field={field} />
    </div>
  );
}

/**
 * The integration request form with an optional "buy me a coffee" PayPal donation.
 */
function RequestForm({ onSent }) {
  const [form, setForm] = useState(EMPTY);
  const [amount, setAmount] = useState('5');
  const [donate, setDonate] = useState(false);
  const min = config.author.minDonation || 1;
  const value = parseAmount(amount, min);
  const amountError = amount !== '' && value === null ? sprintf( __( 'Enter at least $%s.', 'wp-activity-ledger' ), min ) : '';
  const set = (patch) => setForm((current) => ({ ...current, ...patch }));

  const send = useMutation({ mutationFn: async (body) => (await api('/integration-request', { method: 'POST', body })).data });
  const errors = send.error?.data?.errors ?? {};
  const mailFailed = send.error?.code === 'wpal_mail_failed';

  const submit = (event) => {
    event.preventDefault();

    if (donate && value === null) return;

    // Open the PayPal tab during the click (a tab opened after the request would be blocked).
    const tab = donate ? window.open('', '_blank') : null;

    send.mutate(form, {
      onSuccess: () => {
        let opened = false;

        if (tab) {
          tab.opener = null;
          tab.location.href = paypalUrl(config.author.paypalMe, value);
          opened = true;
        }

        onSent({ email: form.email, donated: donate, opened, url: paypalUrl(config.author.paypalMe, value) });
      },
      onError: () => tab?.close(),
    });
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="wpal-req-name" label={__( 'Your name', 'wp-activity-ledger' )} errors={errors} field="name">
          <Input id="wpal-req-name" value={form.name} maxLength={100} onChange={(e) => set({ name: e.target.value })} />
        </Field>
        <Field id="wpal-req-email" label={__( 'Your email', 'wp-activity-ledger' )} hint={__( '(for my reply)', 'wp-activity-ledger' )} errors={errors} field="email">
          <Input id="wpal-req-email" type="email" required value={form.email} onChange={(e) => set({ email: e.target.value })} />
        </Field>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="wpal-req-plugin" label={__( 'Plugin', 'wp-activity-ledger' )} errors={errors} field="plugin">
          <Input id="wpal-req-plugin" required maxLength={100} placeholder={__( 'e.g. Gravity Forms', 'wp-activity-ledger' )} value={form.plugin} onChange={(e) => set({ plugin: e.target.value })} />
        </Field>
        <Field id="wpal-req-url" label={__( 'Plugin link', 'wp-activity-ledger' )} hint={__( '(optional)', 'wp-activity-ledger' )} errors={errors} field="plugin_url">
          <Input id="wpal-req-url" type="url" placeholder="https://wordpress.org/plugins/…" value={form.plugin_url} onChange={(e) => set({ plugin_url: e.target.value })} />
        </Field>
      </div>
      <Field id="wpal-req-message" label={__( 'What should be logged?', 'wp-activity-ledger' )} hint={__( '(optional)', 'wp-activity-ledger' )} errors={errors} field="message">
        <textarea
          id="wpal-req-message"
          rows={5}
          maxLength={2000}
          placeholder={__( 'e.g. form entries edited or deleted, settings changes…', 'wp-activity-ledger' )}
          className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          value={form.message}
          onChange={(e) => set({ message: e.target.value })}
        />
      </Field>

      <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
        <div className="flex items-center gap-2 text-base font-semibold text-amber-900">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-amber-100">
            <Coffee className="h-4 w-4 text-amber-700" aria-hidden="true" />
          </span>
          {__( 'Buy me a coffee', 'wp-activity-ledger' )}
          <span className="text-sm font-normal text-amber-800/80">{__( '(optional)', 'wp-activity-ledger' )}</span>
        </div>
        <p className="text-xs text-amber-900/80">{__( 'Activity Ledger is free. A coffee helps me build the next integration. Payments go through PayPal.', 'wp-activity-ledger' )}</p>
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative w-28">
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
            <Input
              aria-label={__( 'Amount in US dollars', 'wp-activity-ledger' )}
              inputMode="decimal"
              className="border-amber-300 bg-white pl-6 focus-visible:ring-amber-500"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
          </div>
          <span className="text-sm text-amber-900/80">USD</span>
          <Button
            type="button"
            className="bg-amber-500 font-semibold text-amber-950 shadow-sm hover:bg-amber-400 hover:text-amber-950 focus-visible:ring-amber-600"
            disabled={value === null}
            onClick={() => window.open(paypalUrl(config.author.paypalMe, value), '_blank', 'noopener')}
          >
            <Coffee className="h-4 w-4" />
            {__( 'Buy me a coffee', 'wp-activity-ledger' )}
          </Button>
        </div>
        {amountError && <p className="text-xs text-destructive">{amountError}</p>}
        <label className="flex cursor-pointer items-center gap-2 text-sm text-amber-950">
          {/* A real checkbox (keyboard, screen readers) drawn by us: wp-admin's checkbox styles clash with the app reset. */}
          <span className="relative flex h-4 w-4 shrink-0">
            <input type="checkbox" className="peer absolute inset-0 !m-0 h-4 w-4 cursor-pointer opacity-0" checked={donate} onChange={(e) => setDonate(e.target.checked)} />
            <span
              aria-hidden="true"
              className="flex h-4 w-4 items-center justify-center rounded-sm border border-amber-400 bg-white peer-checked:border-amber-500 peer-checked:bg-amber-500 peer-focus-visible:ring-2 peer-focus-visible:ring-amber-600"
            >
              {donate && <Check className="h-3 w-3 text-amber-950" />}
            </span>
          </span>
          {__( 'Also open PayPal with this amount when I send the request', 'wp-activity-ledger' )}
        </label>
      </div>

      <p className="text-xs text-muted-foreground">
        {__( 'Sent by email from this site. It includes your name and email, the site address, and the Activity Ledger and WordPress versions.', 'wp-activity-ledger' )}
      </p>

      {send.isError && (
        <div className="space-y-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
          <p className="text-destructive">{send.error.message}</p>
          {mailFailed && (
            <a href={mailtoLink(form)} className="inline-flex items-center gap-1.5">
              <Mail className="h-4 w-4" aria-hidden="true" />
              {sprintf( __( 'Email it yourself to %s', 'wp-activity-ledger' ), config.author.requestEmail )}
            </a>
          )}
        </div>
      )}

      <div className="flex justify-end">
        <Button type="submit" disabled={send.isPending || (donate && value === null)}>
          <Send className="h-4 w-4" />
          {send.isPending ? __( 'Sending…', 'wp-activity-ledger' ) : __( 'Send request', 'wp-activity-ledger' )}
        </Button>
      </div>
    </form>
  );
}

/**
 * The thank-you view after sending.
 */
function Sent({ result, onAnother }) {
  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-4">
        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
        <div className="space-y-1 text-sm">
          <p className="font-medium">{__( 'Thanks, your request was sent.', 'wp-activity-ledger' )}</p>
          <p className="text-muted-foreground">{sprintf( __( 'I will reply to %s.', 'wp-activity-ledger' ), result.email )}</p>
        </div>
      </div>
      {result.donated && (
        <p className="text-sm">
          {result.opened ? __( 'PayPal opened in a new tab. Thank you for the coffee!', 'wp-activity-ledger' ) : __( 'Your browser blocked the PayPal tab.', 'wp-activity-ledger' )}{' '}
          <a href={result.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1">
            {__( 'Open PayPal', 'wp-activity-ledger' )}
            <ExternalLink className="h-3 w-3" aria-hidden="true" />
          </a>
        </p>
      )}
      <Button variant="outline" onClick={onAnother}>
        {__( 'Send another request', 'wp-activity-ledger' )}
      </Button>
    </div>
  );
}

/**
 * "Request an integration" button with its side panel.
 */
export default function IntegrationRequest() {
  const [open, setOpen] = useState(false);
  const [result, setResult] = useState(null);

  return (
    <>
      <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)}>
        <MessageSquarePlus className="h-4 w-4" />
        {__( 'Request an integration', 'wp-activity-ledger' )}
      </Button>
      <Sheet
        open={open}
        onOpenChange={(next) => {
          setOpen(next);
          if (!next) setResult(null);
        }}
      >
        <SheetContent
          className="max-w-xl"
          onOpenAutoFocus={(event) => {
            // Name and email are filled in already: start at the plugin field.
            event.preventDefault();
            document.getElementById('wpal-req-plugin')?.focus();
          }}
        >
          <SheetHeader>
            <SheetTitle>{__( 'Request an integration', 'wp-activity-ledger' )}</SheetTitle>
            <SheetDescription>{__( 'Tell me which plugin you would like Activity Ledger to log, and what matters to you. I read every request.', 'wp-activity-ledger' )}</SheetDescription>
          </SheetHeader>
          {result ? <Sent result={result} onAnother={() => setResult(null)} /> : <RequestForm onSent={setResult} />}
        </SheetContent>
      </Sheet>
    </>
  );
}
