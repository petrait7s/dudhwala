'use client';

import { useEffect, useState } from 'react';
import { api, cartChanged } from '@/lib/api';
import { DEMO_BRANDING } from '@/components/developer-credit';
import { DemoCheckout } from '@/components/demo-checkout';
import { OtpLogin } from '@/components/otp-login';

const GUEST_CHECKOUT = process.env.NEXT_PUBLIC_GUEST_CHECKOUT === '1';

/** Live shop: ordering needs a signed-in customer (mobile OTP). The local demo has no login. */
function Gate() {
  const [authed, setAuthed] = useState<boolean | null>(null);
  useEffect(() => {
    api('/me')
      .then(() => setAuthed(true))
      .catch(async () => {
        if (!GUEST_CHECKOUT) return setAuthed(false);
        // Guest checkout (staging, orders confirmed by phone): start an anonymous customer session instead of asking for an SMS code.
        try {
          await api('/auth/guest', { method: 'POST', body: {} });
          cartChanged();
          setAuthed(true);
        } catch {
          setAuthed(false);
        }
      });
  }, []);
  if (authed === null) return <p className="pt-10 text-center">লোড হচ্ছে…</p>;
  if (!authed)
    return (
      <div className="mx-auto max-w-md pt-8">
        <p className="mb-3 text-sm text-muted">অর্ডার দিতে আপনার মোবাইল নম্বর যাচাই করুন।</p>
        <OtpLogin onDone={() => setAuthed(true)} />
      </div>
    );
  return <DemoCheckout />;
}

export default function CheckoutPage() {
  return DEMO_BRANDING ? <DemoCheckout /> : <Gate />;
}
