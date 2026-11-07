import { clearCart, readCart, removeLine, setQty, type CartLine } from '../lib/cart';
import { createOrder, getConfig, hasApi } from '../lib/api';
import { formatTaka, normalizePhone } from '../lib/money';
import { getZone, onZone, setZone } from '../lib/zone';
import { toBn } from './bn';
import { DELIVERY_ESTIMATE, bulkStatus, deliveryFee, orderingOpen, pickupSlots, zoneEligible, hhmm, dhakaMinutes, DEFAULT_CONFIG, type Config, type Method, type OuterStatus, type Zone } from '../lib/rules';

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;
const both = (f: (l: 'bn' | 'en') => string) => `<span class="bn">${f('bn')}</span><span class="en">${f('en')}</span>`;
const tk = (p: number) => both((l) => formatTaka(p, l));
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

let cfg: Config = DEFAULT_CONFIG;
let lines: CartLine[] = [];
let busy = false;

const MSG: Record<string, [string, string]> = {
  ordering_closed: ['এখন অর্ডারের সময় নয়।', 'Ordering is closed right now.'],
  cart_empty: ['কার্ট খালি।', 'Cart is empty.'],
  bulk_requires_collection: ['এই পরিমাণ অর্ডার শুধু শোরুম থেকে সংগ্রহ করা যাবে।', 'An order this size can only be collected at the showroom.'],
  not_bulk: ['শোরুম অর্ডারের জন্য পরিমাণ কম।', 'Quantity is too low for a showroom order.'],
  zone_required: ['এলাকা বাছুন।', 'Choose an area.'],
  item_not_deliverable: ['কিছু পণ্য এই এলাকায় ডেলিভারি হয় না।', 'Some items cannot be delivered to this area.'],
  address_name_required: ['নাম লিখুন।', 'Enter your name.'],
  address_phone_invalid: ['সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)।', 'Enter a valid mobile number (01XXXXXXXXX).'],
  address_line_required: ['ঠিকানা লিখুন।', 'Enter your address.'],
  outlet_required: ['আউটলেট বাছুন।', 'Choose an outlet.'],
  pickup_invalid: ['পিকআপের সময় বাছুন।', 'Choose a pickup time.'],
  pickup_too_soon: ['পিকআপ কমপক্ষে ১ ঘণ্টা পরে হতে হবে।', 'Pickup must be at least 1 hour ahead.'],
  pickup_outside_hours: ['এই সময়ে আউটলেট খোলা নেই।', 'The outlet is closed at that time.'],
  payment_trx_required: ['TrxID দিন।', 'Enter the TrxID.'],
  payment_sender_invalid: ['যে নম্বর থেকে পাঠিয়েছেন সেটি দিন।', 'Enter the number you sent from.'],
  bulk_prepayment_required: ['শোরুম অর্ডারে আগে পেমেন্ট দিতে হবে (বিকাশ/নগদ)।', 'Showroom orders must be paid in advance (bKash/Nagad).'],
  payment_method_invalid: ['পেমেন্ট পদ্ধতি বাছুন।', 'Choose a payment method.'],
  UNPRICED: ['একটি পণ্যের দাম এখনো ঠিক হয়নি।', 'An item has no price yet.'],
  UNAVAILABLE: ['একটি পণ্য এখন পাওয়া যাচ্ছে না।', 'An item is not available.'],
  OUT_OF_STOCK: ['একটি পণ্য স্টকে নেই।', 'An item is out of stock.'],
  QTY_LIMIT: ['একটি পণ্যের সর্বোচ্চ সীমা পার হয়েছে।', 'An item exceeds its limit.'],
  RATE_LIMITED: ['অনেকবার চেষ্টা হয়েছে। একটু পরে আবার চেষ্টা করুন।', 'Too many attempts. Try again shortly.'],
};
const msg = (c: string) => both((l) => (MSG[c] ?? ['কিছু একটা ভুল হয়েছে।', 'Something went wrong.'])[l === 'bn' ? 0 : 1]);

const val = (id: string) => ($(id) as HTMLInputElement).value.trim();
const method = (): Method => (document.querySelector<HTMLInputElement>('input[name=method]:checked')?.value as Method) || 'HOME_DELIVERY';
const pay = () => document.querySelector<HTMLInputElement>('input[name=pay]:checked')?.value || 'COD';
const zone = (): Zone | null => (val('zone') as Zone) || getZone();
const lineTotals = () => lines.map((l) => ({ quantity: l.quantity, line_poisha: l.unitPoisha * l.quantity }));

function bulk() { return bulkStatus(lineTotals(), cfg.bulk); }

function renderLines() {
  const z = method() === 'HOME_DELIVERY' ? zone() : null;
  $('lines').innerHTML = lines.map((l) => {
    const bad = z && !zoneEligible(l.category, l.outer as OuterStatus, z);
    return `<div class="line" data-id="${l.variantId}">
      <div><div class="nm">${both((x) => esc(x === 'bn' ? l.nameBn : l.nameEn))}${l.labelBn ? ' · ' + both((x) => esc(x === 'bn' ? l.labelBn : l.labelEn)) : ''}</div>
      <div class="muted">${tk(l.unitPoisha)}</div>${bad ? `<div class="err">${msg('item_not_deliverable')}</div>` : ''}</div>
      <div style="text-align:right"><div class="qty"><button type="button" data-d="-1" aria-label="−">−</button><output>${l.quantity}</output><button type="button" data-d="1" aria-label="+">+</button></div>
      <div><strong>${tk(l.unitPoisha * l.quantity)}</strong></div><button type="button" class="chip" data-rm style="min-height:32px;margin-top:.3rem">✕</button></div></div>`;
  }).join('');
}

function render() {
  lines = readCart();
  $('empty').hidden = lines.length > 0;
  $('main-lay').hidden = lines.length === 0;
  if (!lines.length) return;
  $('noapi').hidden = hasApi();

  const b = bulk();
  const allowed: Method[] = b.isBulk ? ['BULK_ORDER'] : ['HOME_DELIVERY', 'EXPRESS_PICKUP'];
  document.querySelectorAll<HTMLElement>('#methods .opt').forEach((o) => {
    const m = o.dataset.m as Method; const ok = allowed.includes(m);
    o.setAttribute('aria-disabled', String(!ok));
    (o.querySelector('input') as HTMLInputElement).disabled = !ok;
  });
  const cur = document.querySelector<HTMLInputElement>('input[name=method]:checked');
  if (!cur || cur.disabled) (document.querySelector<HTMLInputElement>(`input[name=method][value=${allowed[0]}]`)!).checked = true;
  const m = method();

  const note = $('bulknote');
  note.hidden = !b.isBulk;
  if (b.isBulk) note.innerHTML = both((l) => l === 'bn'
    ? `শোরুম থেকে অর্ডার (${toBn(String(b.qty))} পিস${b.total !== null ? ', ' + formatTaka(b.total, 'bn') : ''}): হোম ডেলিভারি ও এক্সপ্রেস পিকআপ নেই; আপনার বেছে নেওয়া শাখা থেকে সংগ্রহ।${cfg.bulk_prepaid ? ' আগে পেমেন্ট করতে হবে।' : ''}`
    : `Showroom order (${b.qty} items${b.total !== null ? ', ' + formatTaka(b.total, 'en') : ''}): no home delivery or express pickup; collect at the branch you choose.${cfg.bulk_prepaid ? ' Payment is required in advance.' : ''}`);
  if (cfg.bulk.approved === false && b.isBulk) note.innerHTML += ' ' + both((l) => (l === 'bn' ? '(সীমা এখনো চূড়ান্ত নয়)' : '(threshold not final yet)'));

  $('zonebox').hidden = m !== 'HOME_DELIVERY';
  $('addrbox').hidden = m !== 'HOME_DELIVERY';
  $('outletbox').hidden = m === 'HOME_DELIVERY';
  $('pickbox').hidden = m !== 'EXPRESS_PICKUP';
  showBranch();
  const z = zone();
  $('eta').innerHTML = m === 'HOME_DELIVERY' && z ? both((l) => DELIVERY_ESTIMATE[z][l]) : '';

  renderLines();
  const bad = m === 'HOME_DELIVERY' && z ? lines.filter((l) => !zoneEligible(l.category, l.outer as OuterStatus, z)) : [];
  $('inel').hidden = bad.length === 0;
  if (bad.length) $('inelmsg').innerHTML = both((l) => (l === 'bn' ? `${toBn(String(bad.length))}টি পণ্য আউটার সিলেটে ডেলিভারি করা যায় না। চেকআউটের আগে জোন বদলান অথবা সরান। কিছু নিজে থেকে সরানো হবে না।` : `${bad.length} item(s) cannot be delivered to Outer Sylhet. Change the zone or remove them before checkout. Nothing is removed automatically.`));

  const sub = lines.reduce((s, l) => s + l.unitPoisha * l.quantity, 0);
  const fee = deliveryFee(m, m === 'HOME_DELIVERY' ? z : null, cfg.fees);
  $('sub').innerHTML = tk(sub); $('fee').innerHTML = m === 'HOME_DELIVERY' && !z ? '—' : tk(fee); $('tot').innerHTML = tk(sub + fee);

  const open = orderingOpen(new Date(), cfg.hours);
  const closed = $('closed'); closed.hidden = open;
  if (!open) closed.innerHTML = both((l) => (l === 'bn' ? `এখন অর্ডারের সময় নয়। অর্ডার নেওয়া হয় ${hhmm(cfg.hours.open_minute)} থেকে ${hhmm(cfg.hours.close_minute)} পর্যন্ত।` : `Ordering is closed. Hours: ${hhmm(cfg.hours.open_minute)} to ${hhmm(cfg.hours.close_minute)}.`));

  // payment
  const prepaid = m === 'BULK_ORDER' && cfg.bulk_prepaid;
  document.querySelectorAll<HTMLElement>('#pays .opt').forEach((o) => {
    const p = o.dataset.p!; const num = p === 'BKASH' ? cfg.payment_numbers.bkash : p === 'NAGAD' ? cfg.payment_numbers.nagad : 'x';
    const dis = (p === 'COD' && prepaid) || !num;
    o.setAttribute('aria-disabled', String(dis)); (o.querySelector('input') as HTMLInputElement).disabled = dis;
    const hint = o.querySelector<HTMLElement>('[data-pn]');
    if (hint) hint.innerHTML = num ? both((l) => (l === 'bn' ? 'নম্বর: ' : 'Number: ') + esc(num!)) : both((l) => (l === 'bn' ? 'নম্বর শীঘ্রই' : 'Number coming soon'));
  });
  const pcur = document.querySelector<HTMLInputElement>('input[name=pay]:checked');
  if (!pcur || pcur.disabled) { const f = Array.from(document.querySelectorAll<HTMLInputElement>('input[name=pay]')).find((i) => !i.disabled); if (f) f.checked = true; }
  const pm = pay();
  $('mobbox').hidden = pm === 'COD';
  if (pm !== 'COD') {
    const num = pm === 'BKASH' ? cfg.payment_numbers.bkash : cfg.payment_numbers.nagad;
    $('payhow').innerHTML = both((l) => l === 'bn'
      ? `${esc(num ?? '')} নম্বরে ${formatTaka(sub + fee, 'bn')} পাঠান, তারপর নিচে আপনার নম্বর ও TrxID দিন। আমাদের স্টাফ যাচাই করবেন।`
      : `Send ${formatTaka(sub + fee, 'en')} to ${esc(num ?? '')}, then enter your number and the TrxID below. Our staff will verify it.`);
  }
}

function fillPickup() {
  const sel = $<HTMLSelectElement>('slot');
  const cur = sel.value;
  const branch = cfg.outlets.find((x) => String(x.id) === val('outlet'))?.hours;
  sel.innerHTML = pickupSlots(new Date(), 12, cfg.hours, branch).map((d) => {
    const m = dhakaMinutes(d); const day = new Date(d.getTime() + 6 * 3600_000).toISOString().slice(5, 10);
    return `<option value="${d.toISOString()}">${day} ${hhmm(m)}</option>`;
  }).join('');
  if (cur) sel.value = cur;
}

/** Branch choices (pickup and showroom): every active branch; the default collection branch is preselected. */
function fillOutlets() {
  const o = $<HTMLSelectElement>('outlet');
  const keep = o.value;
  const outs = cfg.outlets;
  o.innerHTML = outs.length ? outs.map((x) => `<option value="${x.id}">${esc(x.name_bn)}${x.name_en ? ' / ' + esc(x.name_en) : ''}</option>`).join('') : '<option value="">—</option>';
  const def = outs.find((x) => x.is_collection) ?? outs[0];
  o.value = keep && outs.some((x) => String(x.id) === keep) ? keep : String(def?.id ?? '');
  fillPickup();
}

function showBranch() {
  const x = cfg.outlets.find((b) => String(b.id) === val('outlet'));
  $('branchinfo').textContent = x ? [x.address_line, x.phone].filter(Boolean).join(' · ') : '';
}

function showErrors(codes: string[]) {
  const e = $('errs'); e.hidden = codes.length === 0;
  e.innerHTML = [...new Set(codes)].map((c) => `<div>${msg(c)}</div>`).join('');
  if (codes.length) e.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function localIssues(): string[] {
  const out: string[] = [];
  const m = method();
  if (!orderingOpen(new Date(), cfg.hours)) out.push('ordering_closed');
  if (!lines.length) out.push('cart_empty');
  const b = bulk();
  if (b.isBulk && m !== 'BULK_ORDER') out.push('bulk_requires_collection');
  if (!b.isBulk && m === 'BULK_ORDER') out.push('not_bulk');
  if (m === 'HOME_DELIVERY') {
    const z = zone(); if (!z) out.push('zone_required'); else if (lines.some((l) => !zoneEligible(l.category, l.outer as OuterStatus, z))) out.push('item_not_deliverable');
    if (!val('addr')) out.push('address_line_required');
  }
  if (!val('name')) out.push('address_name_required');
  if (!normalizePhone(val('phone'))) out.push('address_phone_invalid');
  if ((m === 'EXPRESS_PICKUP' || m === 'BULK_ORDER') && !val('outlet')) out.push('outlet_required');
  if (m === 'EXPRESS_PICKUP' && !val('slot')) out.push('pickup_invalid');
  const p = pay();
  if (p !== 'COD') { if (!val('trx')) out.push('payment_trx_required'); if (!normalizePhone(val('sender'))) out.push('payment_sender_invalid'); }
  if (m === 'BULK_ORDER' && cfg.bulk_prepaid && p === 'COD') out.push('bulk_prepayment_required');
  return out;
}

$('lines').addEventListener('click', (e) => {
  const t = e.target as HTMLElement; const row = t.closest<HTMLElement>('.line'); if (!row) return;
  const id = Number(row.dataset.id); const l = lines.find((x) => x.variantId === id)!;
  if (t.matches('[data-rm]')) removeLine(id);
  else if (t.dataset.d) setQty(id, l.quantity + Number(t.dataset.d));
});
['change', 'input'].forEach((ev) => $('f').addEventListener(ev, (e) => {
  const t = e.target as HTMLElement;
  if (t.matches('select#outlet')) { fillPickup(); showBranch(); return; }
  if (t.matches('select#zone')) { setZone(val('zone') as Zone); return; } // the zone bar and this form share one zone
  if (t.matches('input[type=radio]')) render();
}));
onZone(() => { $<HTMLSelectElement>('zone').value = getZone(); render(); });
$('toin').addEventListener('click', () => setZone('IN_CITY'));
$('rmbad').addEventListener('click', () => {
  const z = zone(); if (!z) return;
  lines.filter((l) => !zoneEligible(l.category, l.outer as OuterStatus, z)).forEach((l) => removeLine(l.variantId));
});
window.addEventListener('dw:cart', render);

$('f').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (busy) return;
  const issues = localIssues();
  if (issues.length) return showErrors(issues);
  if (!hasApi()) return showErrors(['UNAVAILABLE']);
  busy = true; const btn = $<HTMLButtonElement>('go'); btn.disabled = true;
  const original = btn.innerHTML; btn.innerHTML = both((l) => (l === 'bn' ? 'অর্ডার হচ্ছে…' : 'Placing order…'));
  let key = ''; try { key = sessionStorage.getItem('dw_idem') || ''; } catch {}
  if (!key) { key = crypto.randomUUID(); try { sessionStorage.setItem('dw_idem', key); } catch {} }
  const m = method(); const p = pay();
  try {
    const r = await createOrder({
      idempotency_key: key, method: m, zone: m === 'HOME_DELIVERY' ? zone() : null,
      items: lines.map((l) => ({ variant_id: l.variantId, quantity: l.quantity })),
      contact: { name: val('name'), phone: normalizePhone(val('phone'))!, ...(m === 'HOME_DELIVERY' ? { address_line1: val('addr'), landmark: val('land') } : {}) },
      outlet_id: m === 'EXPRESS_PICKUP' || m === 'BULK_ORDER' ? Number(val('outlet')) || null : null,
      pickup_at: m === 'EXPRESS_PICKUP' ? val('slot') : null,
      payment: p === 'COD' ? { method: 'COD' } : { method: p, trx_id: val('trx'), sender_number: normalizePhone(val('sender'))! },
      website: val('website'),
    });
    try { sessionStorage.setItem('dw_last_order', JSON.stringify({ n: r.order_number, t: r.total_poisha, m, p })); sessionStorage.removeItem('dw_idem'); } catch {}
    clearCart();
    location.href = `/order-success?o=${encodeURIComponent(r.order_number)}`;
  } catch (err: any) {
    const issues: string[] = err?.body?.error?.issues?.map((i: any) => i.code) ?? [];
    const code = err?.status === 429 ? 'RATE_LIMITED' : err?.body?.error?.code || 'ERROR';
    showErrors(issues.length ? issues : [code]);
    btn.disabled = false; btn.innerHTML = original; busy = false;
  }
});

const qs = new URLSearchParams(location.search);
getConfig().then((c) => { cfg = c; fillOutlets(); const want = qs.get('method'); if (want === 'BULK_ORDER' && bulk().isBulk) document.querySelector<HTMLInputElement>('input[value=BULK_ORDER]')!.checked = true; render(); });
$<HTMLSelectElement>('zone').value = getZone();
fillOutlets(); render();
