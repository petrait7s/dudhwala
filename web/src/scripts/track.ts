import { hasApi, trackOrder } from '../lib/api';
import { formatTaka, normalizePhone } from '../lib/money';

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;
const both = (bn: string, en: string) => `<span class="bn">${bn}</span><span class="en">${en}</span>`;
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

const FLOW: Record<string, string[]> = {
  HOME_DELIVERY: ['PLACED', 'PREPARING', 'OUT_FOR_DELIVERY', 'DELIVERED'],
  EXPRESS_PICKUP: ['PICKUP_REQUESTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP'],
  BULK_ORDER: ['PLACED', 'PREPARING', 'READY_FOR_COLLECTION', 'COLLECTED'],
};
const LABEL: Record<string, [string, string]> = {
  PLACED: ['অর্ডার নেওয়া হয়েছে', 'Order received'], PREPARING: ['প্রস্তুত করা হচ্ছে', 'Being prepared'],
  OUT_FOR_DELIVERY: ['ডেলিভারির পথে', 'Out for delivery'], DELIVERED: ['ডেলিভারি সম্পন্ন', 'Delivered'],
  PICKUP_REQUESTED: ['পিকআপের অনুরোধ নেওয়া হয়েছে — এখনো নিশ্চিত নয়', 'Pickup requested — not confirmed yet'],
  READY_FOR_PICKUP: ['পিকআপ নিশ্চিত — অর্ডার প্রস্তুত', 'Pickup confirmed — order ready'], PICKED_UP: ['পিকআপ সম্পন্ন', 'Picked up'],
  READY_FOR_COLLECTION: ['সংগ্রহের জন্য প্রস্তুত', 'Ready for collection'], COLLECTED: ['সংগ্রহ সম্পন্ন', 'Collected'],
  CANCELLED: ['বাতিল করা হয়েছে', 'Cancelled'],
};
const PAY: Record<string, [string, string]> = {
  UNPAID: ['পেমেন্ট বাকি', 'Payment due'], PENDING_VERIFICATION: ['পেমেন্ট যাচাই চলছে', 'Payment being verified'], PAID: ['পেমেন্ট নেওয়া হয়েছে', 'Paid'], VOID: ['বাতিল', 'Void'],
};

const qs = new URLSearchParams(location.search);
if (qs.get('o')) ($('on') as HTMLInputElement).value = qs.get('o')!;
$('noapi').hidden = hasApi();

$('tf').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = $('te'); err.hidden = true;
  const num = ($('on') as HTMLInputElement).value.trim().toUpperCase();
  const ph = normalizePhone(($('ph') as HTMLInputElement).value);
  if (!num || !ph) { err.innerHTML = both('অর্ডার নম্বর ও সঠিক মোবাইল নম্বর দিন।', 'Enter the order number and a valid mobile number.'); err.hidden = false; return; }
  if (!hasApi()) return;
  const b = $<HTMLButtonElement>('tb'); b.disabled = true;
  try {
    const o = await trackOrder(num, ph);
    const flow = FLOW[o.method] ?? [];
    const cancelled = o.status === 'CANCELLED';
    const idx = flow.indexOf(o.status);
    const lis = cancelled ? `<li class="now done">${both(...LABEL.CANCELLED!)}</li>` : flow.map((s, i) => `<li class="${i < idx ? 'done' : i === idx ? 'done now' : ''}">${both(...(LABEL[s] ?? [s, s]))}</li>`).join('');
    const items = (o.items ?? []).map((i: any) => `<div class="row"><span>${esc(i.name_bn)}${i.variant_label_bn ? ' · ' + esc(i.variant_label_bn) : ''} × ${i.quantity}</span><span>${both(formatTaka(i.line_total_poisha, 'bn'), formatTaka(i.line_total_poisha, 'en'))}</span></div>`).join('');
    const pay = o.payment_status ? `<span class="pill ${o.payment_status === 'PAID' ? 'ok' : ''}">${both(...(PAY[o.payment_status] ?? [o.payment_status, o.payment_status]))}</span>` : '';
    const COUR: Record<string, [string, string]> = { STEADFAST: ['স্টেডফাস্ট', 'Steadfast'], PATHAO: ['পাঠাও', 'Pathao'], OWN_RIDER: ['আমাদের রাইডার', 'Our rider'], OTHER: ['কুরিয়ার', 'Courier'] };
    const cour = o.courier ? `<p class="notice">${both('কুরিয়ার: ' + (COUR[o.courier]?.[0] ?? o.courier) + (o.courier_ref ? ' · ট্র্যাকিং কোড: ' + esc(o.courier_ref) : ''), 'Courier: ' + (COUR[o.courier]?.[1] ?? o.courier) + (o.courier_ref ? ' · Tracking code: ' + esc(o.courier_ref) : ''))}${/^https?:\/\//.test(o.courier_url || '') ? `<br><a class="btn primary sm" style="margin-top:.5rem" href="${esc(o.courier_url)}" target="_blank" rel="noopener noreferrer">${both('কুরিয়ারের সাইটে ট্র্যাক করুন', 'Track on the courier site')}</a>` : ''}</p>` : '';
    $('res').innerHTML = `<div class="stat"><strong style="font-size:1.2rem">${esc(o.order_number)}</strong> ${pay}</div><ul class="tl">${lis}</ul>${cour}${items}
      <div class="row tot"><span>${both('মোট', 'Total')}</span><span>${both(formatTaka(o.total_poisha, 'bn'), formatTaka(o.total_poisha, 'en'))}</span></div>`;
    $('res').hidden = false;
  } catch (x: any) {
    err.innerHTML = x?.status === 429 ? both('অনেকবার চেষ্টা হয়েছে। একটু পরে আবার চেষ্টা করুন।', 'Too many attempts. Try again shortly.') : both('অর্ডার পাওয়া যায়নি। নম্বর ও ফোন মিলিয়ে দেখুন।', 'Order not found. Check the number and phone.');
    err.hidden = false; $('res').hidden = true;
  } finally { b.disabled = false; }
});
