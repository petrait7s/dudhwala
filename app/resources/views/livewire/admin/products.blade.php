<div>
<style>
.sheet-top{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between;margin-bottom:1rem}
.sheet-top h2{margin:0}
.btn.ic{padding:.5rem .8rem}.btn.ic svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sheet-tools{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}.sheet-tools input,.sheet-tools select{margin:0;width:auto;min-width:0}.sheet-tools .grow input{width:100%}
.editbar{display:none;align-items:center;gap:.6rem;flex-wrap:wrap;background:var(--warnbg);color:var(--warn);border-radius:12px;padding:.55rem .9rem;margin:.8rem 0;font-weight:700;position:sticky;top:64px;z-index:15}
.editing .editbar{display:flex}.editbar .sp{flex:1}
.sheet-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px}
.sheet{min-width:980px}
.sheet th{position:sticky;top:0;background:linear-gradient(180deg,#3a40b0,#2b2f9e);color:#fff;font-size:.74rem;text-transform:none;letter-spacing:0;border:0}
.sheet th.c,.sheet td.c{text-align:center}
.sheet td{background:var(--cell);vertical-align:middle;padding:.4rem .6rem}
.sheet tr:nth-child(even) td{background:#ebeeff}
.sheet td.nm b{display:block;color:var(--teal)}.sheet td.nm small{color:var(--muted)}
.sheet tr.more td.nm{opacity:.45}
.cell{display:none;width:100%;margin:0;padding:.35rem .55rem;border:1.5px solid var(--brand2);border-radius:8px;background:#fff;font-family:Montserrat,"Tiro Bangla",sans-serif}
.editing .cell{display:block}.editing .val{display:none}
.cell.dirty{background:#fff3b8;border-color:#d9a400}
.sw{appearance:none;border:0;padding:0;background:none;cursor:default;display:inline-flex;align-items:center;gap:.4rem;font:inherit;color:inherit}
.sw .t{width:38px;height:22px;border-radius:99px;background:#b9c0ee;position:relative;transition:background .18s;flex:none}
.sw .t::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .18s cubic-bezier(.34,1.56,.64,1)}
.sw.on .t{background:var(--ok)}.sw.on .t::after{transform:translateX(16px)}
.sw .l{font-size:.72rem;font-weight:700;min-width:2.5em;text-align:left}.sw.on .l{color:var(--ok)}.sw:not(.on) .l{color:var(--bad)}
.editing .sw{cursor:pointer}.sw.dirty .t{outline:3px solid #f0c93a}.sw:disabled{opacity:.6}
.qa{display:none;gap:.3rem;flex-wrap:wrap;margin-top:.35rem}
.editing .qa{display:flex}
.chip{font:inherit;font-size:.72rem;font-weight:700;border:1px solid var(--line);background:linear-gradient(180deg,#eef0ff,#dfe3ff);color:var(--teal);border-radius:99px;padding:.12rem .6rem;cursor:pointer;transition:transform .12s,background .15s}
.chip:hover{background:#d3d9ff}.chip:active{transform:scale(.95)}.chip.bad{color:var(--bad);background:#fbe0dd;border-color:#f0b8b3}
.sheet tr.fresh td{background:#e3f6ee}
.sheet-note{margin-top:.8rem;color:var(--muted);font-size:.8rem}
.toastmsg{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:60;padding:.6rem 1.2rem;border-radius:99px;font-weight:700;color:#fff;background:#0f1442;box-shadow:0 10px 30px rgba(0,0,0,.3)}
.toastmsg.bad{background:#b3261e}
</style>

<div x-data="dwSheet(@js($data), @js($canManage), @js(['changes' => __('admin.sheet_changes'), 'in' => __('admin.sheet_in'), 'out' => __('admin.sheet_out'), 'saved' => __('admin.saved')]))" :class="{ editing }">
  <div class="sheet-top">
    <h2>{{ __('admin.nav.products') }}</h2>
    <div class="row">
      @if($canManage)<a class="btn alt" href="{{ route('admin.product.new') }}">{{ __('admin.new') }}</a>@endif
      @if($canManage)<button type="button" class="btn ic" x-show="!editing" @click="start()" aria-label="{{ __('admin.sheet_edit') }}" title="{{ __('admin.sheet_edit') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"/></svg><span>{{ __('admin.sheet_edit') }}</span></button>@endif
    </div>
  </div>

  <div class="card">
    <div class="sheet-tools">
      <div class="grow" style="flex:1;min-width:160px"><input type="search" x-model="q" placeholder="{{ __('admin.search_products') }}"></div>
      <select x-model="cat"><option value="">{{ __('admin.all_categories') }}</option><template x-for="c in d.categories" :key="c.id"><option :value="c.id" x-text="c.name"></option></template></select>
      <select x-model="vis"><option value="">{{ __('admin.all') }}</option><option value="1">{{ __('admin.active') }}</option><option value="0">{{ __('admin.hidden') }}</option></select>
    </div>

    <div class="editbar" role="status">
      <span x-text="t.changes.replace(':n', dirtyCount())"></span><span class="sp"></span>
      <button type="button" class="btn alt" @click="cancel()">{{ __('admin.sheet_cancel') }}</button>
      <button type="button" class="btn" :disabled="busy" @click="save()">{{ __('admin.sheet_save') }}</button>
    </div>

    <div class="sheet-wrap" style="margin-top:.8rem">
      <table class="sheet">
        <thead><tr>
          <th>{{ __('admin.name_bn') }}</th><th>{{ __('admin.category') }}</th><th>{{ __('admin.sheet_size_bn') }}</th><th>{{ __('admin.sheet_size_en') }}</th><th>{{ __('admin.price') }}</th>
          <template x-for="o in d.outlets" :key="o.id"><th class="c" x-text="o.name"></th></template>
          <th class="c">{{ __('admin.sheet_show') }}</th>
        </tr></thead>
        <tbody>
          <template x-for="r in visibleRows()" :key="r.key">
            <tr :class="{ more: r.i > 0, fresh: r.new }" :data-key="r.key">
              <td class="nm"><a :href="r.p.url"><b x-text="r.p.name_bn"></b></a><small x-text="r.p.name_en"></small>
                <div class="qa" x-show="editing && r.i === 0 && !r.new || editing && r.new">
                  <template x-if="!r.new"><span style="display:contents"><button type="button" class="chip" @click="addSize(r.p, 'pc')">+ {{ __('admin.sheet_pc') }}</button><button type="button" class="chip" @click="addSize(r.p, 'kg')">+ {{ __('admin.sheet_kg') }}</button><button type="button" class="chip" @click="addSize(r.p, '')">+ {{ __('admin.sheet_size') }}</button></span></template>
                  <template x-if="r.new"><button type="button" class="chip bad" @click="removeRow(r)">✕ {{ __('admin.sheet_remove') }}</button></template>
                </div></td>
              <td x-text="r.p.category"></td>
              <template x-if="r.v">
                <td><span class="val" x-text="r.v.label_bn || '—'"></span><input class="cell" data-col="lb" :class="{ dirty: r.v.label_bn !== r.o.label_bn }" x-model="r.v.label_bn" list="dw-presets" @input="preset(r)" @keydown.enter.prevent="down($event)"></td>
              </template>
              <template x-if="r.v">
                <td><span class="val" x-text="r.v.label_en || '—'"></span><input class="cell" data-col="le" :class="{ dirty: r.v.label_en !== r.o.label_en }" x-model="r.v.label_en" @keydown.enter.prevent="down($event)"></td>
              </template>
              <template x-if="r.v">
                <td><span class="val" x-show="r.v.price !== ''" x-text="'৳' + r.v.price"></span><span class="val pill warn" x-show="r.v.price === ''">{{ __('admin.unpriced') }}</span><input class="cell" data-col="pr" inputmode="decimal" :class="{ dirty: r.v.price !== r.o.price }" x-model="r.v.price" @keydown.enter.prevent="down($event)" placeholder="0"></td>
              </template>
              <template x-if="!r.v"><td colspan="3" class="muted"><a :href="r.p.url">{{ __('admin.sheet_no_sizes') }}</a></td></template>
              <template x-for="o in d.outlets" :key="o.id">
                <td class="c">
                  <template x-if="r.v">
                    <button type="button" class="sw" :class="{ on: r.v.stock[o.id], dirty: r.v.stock[o.id] !== r.o.stock[o.id] }" :disabled="r.v.untracked" @click="flip(r, o.id)" role="switch" :aria-checked="r.v.stock[o.id]"><span class="t"></span><span class="l" x-text="r.v.stock[o.id] ? t.in : t.out"></span></button>
                  </template>
                </td>
              </template>
              <td class="c">
                <template x-if="r.i === 0">
                  <button type="button" class="sw" :class="{ on: r.p.active, dirty: r.p.active !== r.p.o_active }" @click="togglePublish(r.p)" role="switch" :aria-checked="r.p.active"><span class="t"></span><span class="l" x-text="r.p.active ? '✓' : '✕'"></span></button>
                </template>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <datalist id="dw-presets"><template x-for="p in presets" :key="p.bn"><option :value="p.bn" x-text="p.en"></option></template></datalist>
    <p class="sheet-note">{{ __('admin.sheet_tip') }}</p>
  </div>

  <div class="toastmsg" :class="{ bad: toast.bad }" x-show="toast.msg" x-transition x-cloak x-text="toast.msg" role="status"></div>
</div>

<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('dwSheet', (d, canManage, t) => ({
    d, t, canManage, q: '', cat: '', vis: '', editing: false, busy: false, toast: { msg: '', bad: false },
    rows: [], seq: 0,
    presets: [{ bn: '১ পিস', en: '1 pc', k: 'pc' }, { bn: '১ কেজি', en: '1 kg', k: 'kg' }, { bn: 'আধা কেজি', en: '½ kg' }, { bn: '২৫০ গ্রাম', en: '250 g' }, { bn: '৫০০ গ্রাম', en: '500 g' }, { bn: '১ ডজন', en: '1 dozen' }, { bn: '১ প্যাকেট', en: '1 packet' }, { bn: '১ বক্স', en: '1 box' }],
    init() {
      this.d.products.forEach((p) => { p.o_active = p.active; });
      this.rows = [];
      this.d.products.forEach((p) => {
        if (!p.variants.length) { this.rows.push({ key: 'p' + p.id, p, v: null, o: null, i: 0, new: false }); return; }
        p.variants.forEach((v, i) => this.rows.push({ key: 'v' + v.id, p, v, o: JSON.parse(JSON.stringify(v)), i, new: false }));
      });
    },
    visibleRows() {
      const q = this.q.trim().toLowerCase();
      return this.rows.filter((r) => (!q || (r.p.name_bn + ' ' + r.p.name_en).toLowerCase().includes(q)) && (this.cat === '' || String(r.p.category_id) === String(this.cat)) && (this.vis === '' || (this.vis === '1') === r.p.active));
    },
    start() { if (this.canManage) this.editing = true; },
    flip(r, id) { if (this.editing && !r.v.untracked) r.v.stock[id] = !r.v.stock[id]; },
    togglePublish(p) { if (this.editing) p.active = !p.active; },
    rowChange(r) {
      const c = {};
      if (r.new) {
        if (r.v.label_bn.trim() === '' && String(r.v.price).trim() === '') return null;
        return { variant_id: 0, product_id: r.p.id, key: r.key, label_bn: r.v.label_bn, label_en: r.v.label_en, price: r.v.price, stock: Object.assign({}, r.v.stock), ...(r.i === 0 && r.p.active !== r.p.o_active ? { active: r.p.active } : {}) };
      }
      if (r.v) {
        if (r.v.label_bn !== r.o.label_bn) c.label_bn = r.v.label_bn;
        if (r.v.label_en !== r.o.label_en) c.label_en = r.v.label_en;
        if (r.v.price !== r.o.price) c.price = r.v.price;
        const st = {};
        Object.keys(r.v.stock).forEach((k) => { if (r.v.stock[k] !== r.o.stock[k]) st[k] = r.v.stock[k]; });
        if (Object.keys(st).length) c.stock = st;
      }
      if (r.i === 0 && r.p.active !== r.p.o_active) c.active = r.p.active;
      return Object.keys(c).length ? Object.assign({ variant_id: r.v ? r.v.id : (r.p.variants[0] ? r.p.variants[0].id : 0) }, c) : null;
    },
    changes() { return this.rows.map((r) => this.rowChange(r)).filter((c) => c); },
    dirtyCount() {
      let n = 0;
      this.rows.forEach((r) => {
        const c = this.rowChange(r); if (!c) return;
        if (r.new) { n += 1; return; }
        ['label_bn', 'label_en', 'price', 'active'].forEach((k) => { if (k in c) n++; });
        if (c.stock) n += Object.keys(c.stock).length;
      });
      return n;
    },
    cancel() {
      this.rows.filter((r) => r.new).forEach((r) => this.removeRow(r, true));
      this.rows.forEach((r) => { if (r.v) { r.v.label_bn = r.o.label_bn; r.v.label_en = r.o.label_en; r.v.price = r.o.price; r.v.stock = JSON.parse(JSON.stringify(r.o.stock)); } r.p.active = r.p.o_active; });
      this.editing = false;
    },
    async save() {
      const ch = this.changes(); if (!ch.length) { this.editing = false; return; }
      this.busy = true;
      try {
        const res = await this.$wire.saveSheet(ch);
        if (res.ok) {
          this.rows.forEach((r) => { if (r.new && res.created && res.created[r.key]) { r.v.id = res.created[r.key]; r.new = false; } });
          this.rows.forEach((r) => { if (r.v) r.o = JSON.parse(JSON.stringify(r.v)); r.p.o_active = r.p.active; });
          this.editing = false; this.say(this.t.saved, false);
        } else this.say(res.msg, true);
      } catch (e) { this.say('Error', true); }
      this.busy = false;
    },
    addSize(p, kind) {
      const pre = kind ? this.presets.find((x) => x.k === kind) : null;
      const mine = this.rows.filter((r) => r.p === p);
      if (pre) { const have = mine.find((r) => r.v && r.v.label_bn === pre.bn); if (have) { this.focusPrice(have.key); return; } }
      // imported products carry one empty size: fill it instead of leaving it behind next to the new one
      const blank = mine.find((r) => r.v && !r.new && r.v.label_bn === '' && r.v.price === '' && mine.filter((x) => x.v).length === 1);
      if (blank) { if (pre) { blank.v.label_bn = pre.bn; blank.v.label_en = pre.en; } this.focusPrice(blank.key, !pre); return; }
      const first = mine.find((r) => r.v);
      const stock = {}; this.d.outlets.forEach((o) => { stock[o.id] = first ? !!first.v.stock[o.id] : true; });
      const blank = {}; this.d.outlets.forEach((o) => { blank[o.id] = false; });
      const key = 'n' + (++this.seq);
      const row = { key, p, v: { id: 0, label_bn: pre ? pre.bn : '', label_en: pre ? pre.en : '', price: '', untracked: false, stock }, o: { label_bn: '', label_en: '', price: '', stock: blank }, i: first ? mine.length : 0, new: true };
      const ph = mine.find((r) => !r.v);
      const at = ph ? this.rows.indexOf(ph) : this.rows.indexOf(mine[mine.length - 1]) + 1;
      this.rows.splice(at, ph ? 1 : 0, row);
      this.focusPrice(key, !pre);
    },
    removeRow(r, quiet) {
      const at = this.rows.indexOf(r); if (at < 0) return;
      this.rows.splice(at, 1);
      if (!this.rows.some((x) => x.p === r.p)) this.rows.splice(at, 0, { key: 'p' + r.p.id, p: r.p, v: null, o: null, i: 0, new: false });
      else this.rows.filter((x) => x.p === r.p).forEach((x, i) => { x.i = i; });
    },
    preset(r) {
      const m = this.presets.find((x) => x.bn === r.v.label_bn);
      if (m && (r.v.label_en === '' || this.presets.some((x) => x.en === r.v.label_en))) r.v.label_en = m.en;
    },
    focusPrice(key, label) {
      setTimeout(() => { const el = this.$root.querySelector('tr[data-key="' + key + '"] input[data-col="' + (label ? 'lb' : 'pr') + '"]'); if (el) { el.focus(); el.select(); } }, 60);
    },
    say(m, bad) { this.toast = { msg: m, bad }; setTimeout(() => { this.toast.msg = ''; }, 3200); },
    down(e) {
      const col = e.target.dataset.col, all = [...this.$root.querySelectorAll('input.cell[data-col="' + col + '"]')], i = all.indexOf(e.target);
      const nx = all[i + 1]; if (nx) { nx.focus(); nx.select(); }
    },
  }));
});
</script>
</div>
