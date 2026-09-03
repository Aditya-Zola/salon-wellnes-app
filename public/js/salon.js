const money = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value || 0));

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));

const compactInvoiceNumber = (value) => String(value ?? '').replace(/[-_\s]+/g, '');

const localDate = () => {
    const date = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const capabilities = window.SALON_CAPABILITIES || {};
const canCreateReservations = Boolean(capabilities.create_reservation);
const canUpdateReservations = Boolean(capabilities.update_reservation);
const canManageFinance = Boolean(capabilities.manage_finance);
const canManageMemberships = Boolean(capabilities.manage_memberships);
const canViewProducts = Boolean(capabilities.view_products);
const canViewSales = Boolean(capabilities.view_sales);
const canRefundSales = Boolean(capabilities.refund_sales);
const canViewMemberships = Boolean(capabilities.view_memberships);
const canManageTherapistAttendance = Boolean(capabilities.manage_therapist_attendance);
const headerStatusLabels = {
    paid: 'Lunas',
    scheduled: 'Terjadwal',
    arrived: 'Sudah datang',
    in_service: 'Sedang dilayani',
    waiting_payment: 'Menunggu pembayaran',
    completed: 'Selesai',
    cancelled: 'Batal',
    no_show: 'Tidak hadir',
};
const workStatusLabels = {
    waiting: 'Menunggu jadwal',
    in_progress: 'Sedang dilayani',
    continue: 'Dilanjutkan',
    ready: 'Persiapan / istirahat',
    finished: 'Selesai',
    overtime: 'Lembur',
    cancelled: 'Batal',
};
const editableHeaderStatuses = ['scheduled', 'arrived', 'in_service', 'cancelled'];
const headerStatusTransitions = {
    scheduled: ['arrived', 'cancelled'],
    arrived: ['cancelled'],
    in_service: ['cancelled'],
    cancelled: [],
    completed: [],
};

let state = window.SALON_DATA || {};
let revenueChartPeriod = 'week';
let salesPageState = null;
let salesReturnsPageState = null;
let salesView = 'sales';
let salesSearchTimer;
let memberPageState = null;
let memberSearchTimer;
let productPageState = null;
let productSearchTimer;
let stockHistoryPageState = null;
let selectedReservation = null;
let reservationMode = 'today';
let reservationStatusGroup = null;
let calendarMode = 'week';
let pendingReservationPayload = null;
let reservationLaunchContext = 'reservation';
let paymentIdempotencyKey = null;
let paymentMode = null;
let selectedPaymentMethodId = null;
let toastTimer;
let reservationCalendarTooltipTimer;
let reservationCalendarTooltipListenersBound = false;
let reservationCalendarTooltipAnchor = null;
let therapistAttendanceDate = null;
let therapistAttendance = [];
let therapistAttendanceMonth = null;
let therapistAttendanceOffByDate = {};
let stocktakeDraft = new Map();
let financeReports = {};
let financeFiltersNeedReset = false;

const copy = {
    dashboard: ['Dashboard', 'Ringkasan operasional salon hari ini'],
    'reservasi-antrean': ['Antrean Hari Ini', 'Kelola urutan kedatangan dan pelayanan pelanggan'],
    'reservasi-kalender': ['Kalender Reservasi', 'Kelola jadwal treatment dan terapis'],
    'kehadiran-terapis': ['Kehadiran Terapis', 'Atur status masuk atau libur terapis'],
    pegawai: ['Pegawai', 'Kelola master pegawai dan therapist'],
    kasir: ['Kasir', 'Proses pelayanan, diskon member, dan pembayaran'],
    treatment: ['Treatment', 'Kelola menu, paket, harga, dan resep produk'],
    membership: ['Membership', 'Data member dan program khusus'],
    stok: ['Produk & Stok', 'Pantau persediaan dan pergerakan produk'],
    'stok-riwayat': ['Riwayat Keluar-Masuk', 'Telusuri seluruh pergerakan stok produk'],
    'stok-opname': ['Stok opname', 'Tambahkan stok produk yang baru masuk'],
    penjualan: ['Penjualan', 'Riwayat transaksi lunas dan cetak ulang nota'],
    'keuangan-arus-kas': ['Arus Kas', 'Dana masuk, pengeluaran, dan catatan kas salon'],
    'keuangan-laba-rugi': ['Laba-Rugi', 'Pendapatan, HPP, biaya operasional, dan laba bersih'],
    'keuangan-neraca': ['Neraca', 'Posisi aset, kewajiban, dan ekuitas salon'],
    penggajian: ['Penggajian', 'Gaji, bonus, keterlambatan, dan komisi'],
    log: ['Log Aktivitas', 'Jejak perubahan penting seluruh pengguna'],
};

function toast(message, error = false) {
    const element = document.getElementById('toast');
    if (!element) return;

    clearTimeout(toastTimer);
    element.textContent = `${error ? '⚠' : '✓'} ${message}`;
    element.classList.toggle('error', error);
    element.setAttribute('role', 'status');
    element.classList.add('show');
    toastTimer = setTimeout(() => element.classList.remove('show'), 3500);
}

function confirmAction({
    title,
    message,
    confirmLabel = 'Konfirmasi',
    icon = 'check',
}) {
    return new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const dialog = document.createElement('div');
        dialog.className = 'modal open action-confirm-overlay';
        dialog.setAttribute('role', 'presentation');
        dialog.innerHTML = `<section class="action-confirm" role="alertdialog" aria-modal="true" aria-labelledby="action-confirm-title" aria-describedby="action-confirm-message">
            <div class="action-confirm-icon" aria-hidden="true"><span class="material-symbols-outlined">${escapeHtml(icon)}</span></div>
            <div class="action-confirm-copy">
                <span class="action-confirm-eyebrow">Konfirmasi tindakan</span>
                <h2 id="action-confirm-title">${escapeHtml(title)}</h2>
                <p id="action-confirm-message">${escapeHtml(message)}</p>
            </div>
            <div class="action-confirm-actions">
                <button type="button" class="secondary action-confirm-cancel">Kembali</button>
                <button type="button" class="primary action-confirm-submit"><span class="material-symbols-outlined" aria-hidden="true">${escapeHtml(icon)}</span>${escapeHtml(confirmLabel)}</button>
            </div>
        </section>`;

        const cancelButton = dialog.querySelector('.action-confirm-cancel');
        const confirmButton = dialog.querySelector('.action-confirm-submit');
        const finish = (confirmed) => {
            document.removeEventListener('keydown', onKeydown, true);
            dialog.remove();
            previousFocus?.focus?.();
            resolve(confirmed);
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                finish(false);
            }
        };

        cancelButton.onclick = () => finish(false);
        confirmButton.onclick = () => finish(true);
        dialog.onclick = (event) => {
            if (event.target === dialog) finish(false);
        };
        document.addEventListener('keydown', onKeydown, true);
        document.body.appendChild(dialog);
        confirmButton.focus();
    });
}

async function api(url, options = {}) {
    const isFormData = options.body instanceof FormData;
    const response = await fetch(url, {
        ...options,
        headers: {
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...options.headers,
        },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(
            data.message
            || Object.values(data.errors || {})[0]?.[0]
            || 'Permintaan gagal.',
        );
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}

async function refresh() {
    financeReports = {};
    financeFiltersNeedReset = true;
    state = await api('/operasional/data');
    populateSelects();
    renderAll();
    financeFiltersNeedReset = false;
    if (canViewSales) await loadSalesPage((salesView === 'returns' ? salesReturnsPageState : salesPageState)?.meta?.current_page || 1);
    if (canViewMemberships) await loadMembersPage(memberPageState?.meta?.current_page || 1);
    if (canViewProducts) await loadProductsPage(productPageState?.meta?.current_page || 1);
    if (canViewProducts) await loadStockHistoryPage(stockHistoryPageState?.meta?.current_page || 1);
}

function upsertReservation(reservation) {
    if (!reservation?.id) return false;

    state.reservations = [
        ...array(state.reservations).filter((item) => Number(item.id) !== Number(reservation.id)),
        reservation,
    ];
    renderReservations();
    renderCashier();

    return true;
}

function array(value) {
    return Array.isArray(value) ? value : [];
}

function employees() {
    return array(state.employees).length ? array(state.employees) : array(state.therapists);
}

function serviceProviders() {
    return employees().filter((employee) => (
        Number(employee.active ?? employee.is_active ?? 1) === 1
        && Number(employee.is_service_provider ?? 1) === 1
    ));
}

function treatmentPrice(treatment) {
    return Number(treatment?.normal_price ?? treatment?.price ?? 0);
}

function productStock(product) {
    return Number(product?.current_stock ?? product?.stock ?? 0);
}

function productMinimum(product) {
    return Number(product?.minimum_stock ?? 0);
}

function productUnit(product) {
    return product?.usage_unit_code
        || product?.unit
        || product?.usage_unit?.code
        || '';
}

function productUnitOptions(selected = '') {
    const units = array(state.units);
    if (units.length) {
        return units.map((unit) => `<option value="${Number(unit.id)}" ${Number(unit.id) === Number(selected) ? 'selected' : ''}>${escapeHtml(unit.code)} · ${escapeHtml(unit.name)}</option>`).join('');
    }

    return `<option value="${Number(selected) || ''}">${escapeHtml(productUnit({ unit: selected }) || '-')}</option>`;
}

function reservationItems(reservation) {
    if (array(reservation?.items).length) return reservation.items;

    if (reservation?.treatment_name) {
        return [{
            id: reservation.item_id,
            treatment_name: reservation.treatment_name,
            unit_price: reservation.price,
            net_price: reservation.price,
            scheduled_start_at: `${reservation.reservation_date}T${reservation.reservation_time}`,
            work_status: reservation.work_status || 'waiting',
            staff: [{
                employee_id: reservation.therapist_id,
                employee_name: reservation.therapist_name,
                role: 'primary',
            }],
        }];
    }

    return [];
}

function itemStaff(item) {
    return array(item?.staff).length
        ? item.staff
        : (array(item?.staff_assignments).length ? item.staff_assignments : array(item?.employees));
}

function employeeName(assignment) {
    if (!assignment) return '-';
    if (assignment.employee_name || assignment.name) return assignment.employee_name || assignment.name;
    if (assignment.employee?.name) return assignment.employee.name;

    const id = assignment.employee_id ?? assignment.id;
    return employees().find((employee) => Number(employee.id) === Number(id))?.name || '-';
}

function itemTreatmentName(item) {
    return item?.treatment_name || item?.treatment?.name || '-';
}

function itemPrice(item) {
    return Number(item?.net_price ?? item?.unit_price ?? item?.normal_price ?? item?.price ?? 0);
}

function reservationCustomerName(reservation) {
    return reservation?.customer_name || reservation?.customer?.name || 'Pelanggan';
}

function reservationPhone(reservation) {
    return reservation?.phone || reservation?.customer_phone || reservation?.customer?.phone || '';
}

function reservationStatus(reservation) {
    const status = reservation?.status || 'scheduled';
    const items = reservationItems(reservation).filter((item) => item.work_status !== 'cancelled');

    if (status === 'in_service' && items.length && items.every((item) => item.work_status === 'finished')) {
        return 'waiting_payment';
    }

    return status;
}

function statusLabel(status) {
    return headerStatusLabels[status] || status || '-';
}

function itemStartTime(item, reservation) {
    const value = item?.scheduled_start_at || item?.start_at || item?.start_time;
    if (!value) return String(reservation?.reservation_time || '').slice(0, 5);
    if (/^\d{2}:\d{2}/.test(value)) return value.slice(0, 5);

    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) {
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace('.', ':');
    }

    return String(value).slice(11, 16);
}

function itemEndTime(item, reservation) {
    const value = item?.scheduled_end_at || item?.end_at || item?.end_time;
    if (!value) {
        const [hour = 0, minute = 0] = itemStartTime(item, reservation).split(':').map(Number);
        const total = (hour * 60) + minute + Math.max(0, Number(item?.duration_minutes || 0));

        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    }
    if (/^\d{2}:\d{2}/.test(value)) return value.slice(0, 5);

    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) {
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace('.', ':');
    }

    return String(value).slice(11, 16);
}

function itemReadyTime(item, reservation) {
    const value = item?.scheduled_ready_at || item?.ready_at;
    if (!value) {
        const [hour = 0, minute = 0] = itemEndTime(item, reservation).split(':').map(Number);
        const total = (hour * 60) + minute + 45;
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    }
    if (/^\d{2}:\d{2}/.test(value)) return value.slice(0, 5);
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value).slice(11, 16) : date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace('.', ':');
}

function automaticWorkStatus(item, reservation, now = new Date()) {
    if (item?.work_status === 'cancelled') return 'cancelled';
    const at = (value) => {
        if (!value) return null;
        const parsed = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    };
    const start = at(item?.scheduled_start_at || item?.start_at);
    const end = at(item?.scheduled_end_at || item?.end_at);
    const ready = at(item?.scheduled_ready_at) || (end ? new Date(end.getTime() + (45 * 60 * 1000)) : null);

    if (!start || !end || !ready) return item?.work_status || 'waiting';
    if (now < start) return 'waiting';
    if (now < end) return 'in_progress';
    if (now < ready) return 'ready';
    return 'finished';
}

function clockMinutes(value) {
    const match = String(value || '').match(/(\d{1,2})[:.](\d{2})/);
    if (!match) return null;

    return (Number(match[1]) * 60) + Number(match[2]);
}

function reservationItemTiming(item, reservation) {
    const startLabel = itemStartTime(item, reservation) || '09:00';
    const startMinutes = clockMinutes(startLabel) ?? (9 * 60);
    const fallbackDuration = Math.max(15, Number(item?.duration_minutes || 30));
    let endLabel = itemEndTime(item, reservation);
    let endMinutes = clockMinutes(endLabel);

    if (endMinutes === null || endMinutes <= startMinutes) {
        endMinutes = startMinutes + fallbackDuration;
        endLabel = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
    }

    return {
        startLabel,
        endLabel,
        startMinutes,
        endMinutes,
        durationMinutes: Math.max(15, endMinutes - startMinutes),
    };
}

function reservationItemDate(item, reservation) {
    const value = item?.scheduled_start_at || item?.start_at || reservationDate(reservation);
    const match = String(value || '').match(/^\d{4}-\d{2}-\d{2}/);

    return match?.[0] || reservationDate(reservation);
}

function reservationTime(reservation) {
    const first = reservationItems(reservation)[0];
    return itemStartTime(first, reservation) || String(reservation?.reservation_time || '').slice(0, 5);
}

function reservationDate(reservation) {
    return String(reservation?.reservation_date || reservationItems(reservation)[0]?.scheduled_start_at || '').slice(0, 10);
}

function reservationTreatmentSummary(reservation) {
    const names = reservationItems(reservation).map(itemTreatmentName);
    if (!names.length) return '-';
    if (names.length === 1) return names[0];
    return `${names[0]} +${names.length - 1}`;
}

function reservationStaffSummary(reservation) {
    const names = [...new Set(reservationItems(reservation).flatMap((item) => itemStaff(item).map(employeeName)))];
    if (!names.length) return '-';
    if (names.length === 1) return names[0];
    return `${names[0]} +${names.length - 1}`;
}

function reservationStaffIds(reservation) {
    return reservationItems(reservation).flatMap((item) => itemStaff(item).map((staff) => Number(
        staff.employee_id ?? staff.employee?.id ?? staff.id,
    )));
}

function reservationSubtotal(reservation) {
    return reservationItems(reservation)
        .filter((item) => item.work_status !== 'cancelled')
        .reduce((total, item) => total + itemPrice(item), 0);
}

function ensureReservationCalendarTooltip() {
    let tooltip = document.getElementById('reservation-calendar-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'reservation-calendar-tooltip';
        tooltip.className = 'reservation-calendar-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.appendChild(tooltip);
    }

    if (!reservationCalendarTooltipListenersBound) {
        reservationCalendarTooltipListenersBound = true;
        window.addEventListener('resize', hideReservationCalendarTooltip);
        window.addEventListener('scroll', hideReservationCalendarTooltip, true);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') hideReservationCalendarTooltip();
        });
    }

    return tooltip;
}

function hideReservationCalendarTooltip() {
    clearTimeout(reservationCalendarTooltipTimer);
    const tooltip = document.getElementById('reservation-calendar-tooltip');
    if (tooltip) tooltip.hidden = true;
    reservationCalendarTooltipAnchor?.removeAttribute('aria-describedby');
    reservationCalendarTooltipAnchor = null;
}

function positionReservationCalendarTooltip(anchor, tooltip) {
    const anchorRect = anchor.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    const gap = 10;
    const edge = 12;
    let left = anchorRect.right + gap;

    if (left + tooltipRect.width > window.innerWidth - edge) {
        left = anchorRect.left - tooltipRect.width - gap;
    }
    left = Math.max(edge, Math.min(left, window.innerWidth - tooltipRect.width - edge));

    let top = anchorRect.top + ((anchorRect.height - tooltipRect.height) / 2);
    top = Math.max(edge, Math.min(top, window.innerHeight - tooltipRect.height - edge));
    tooltip.style.left = `${Math.round(left)}px`;
    tooltip.style.top = `${Math.round(top)}px`;
}

function showReservationCalendarTooltip(anchor, reservation, item) {
    clearTimeout(reservationCalendarTooltipTimer);
    const tooltip = ensureReservationCalendarTooltip();
    const timing = reservationItemTiming(item, reservation);
    const serviceStatus = reservationStatus(reservation);
    const status = reservationCalendarStatus(reservation);
    const paymentLabel = isAlreadyPaid(reservation) ? 'Lunas' : 'Belum dibayar';
    const staff = itemStaff(item).map(employeeName).join(', ') || '-';
    const workStatus = workStatusLabels[automaticWorkStatus(item, reservation)] || '-';
    const scheduleDate = reservationItemDate(item, reservation);
    const date = new Date(`${scheduleDate}T12:00:00`);
    const dateLabel = Number.isNaN(date.getTime())
        ? scheduleDate
        : new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' }).format(date);

    tooltip.innerHTML = `<div class="reservation-calendar-tooltip-head">
        <span>${escapeHtml(reservation.queue_number || reservation.booking_code || 'Reservasi')}</span>
        <em class="status-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</em>
    </div>
    <strong>${escapeHtml(reservationCustomerName(reservation))}</strong>
    <p class="reservation-calendar-tooltip-time">${escapeHtml(dateLabel)} · ${escapeHtml(timing.startLabel)}–${escapeHtml(timing.endLabel)}</p>
    <dl>
        <div><dt>Treatment</dt><dd>${escapeHtml(itemTreatmentName(item))}</dd></div>
        <div><dt>Therapist</dt><dd>${escapeHtml(staff)}</dd></div>
        <div><dt>Status otomatis</dt><dd>${escapeHtml(workStatus)}</dd></div>
        <div><dt>Status layanan</dt><dd>${escapeHtml(statusLabel(serviceStatus))}</dd></div>
        <div><dt>Pembayaran</dt><dd>${escapeHtml(paymentLabel)}</dd></div>
    </dl>
    <small>Klik untuk membuka detail lengkap</small>`;
    tooltip.hidden = false;
    reservationCalendarTooltipAnchor?.removeAttribute('aria-describedby');
    reservationCalendarTooltipAnchor = anchor;
    anchor.setAttribute('aria-describedby', tooltip.id);
    positionReservationCalendarTooltip(anchor, tooltip);
}

function bindReservationCalendarTooltips(calendar, reservations) {
    calendar.querySelectorAll('.calendar-event').forEach((button) => {
        const reservation = reservations.find((item) => Number(item.id) === Number(button.dataset.id));
        const item = reservationItems(reservation)[Number(button.dataset.itemIndex)] || reservationItems(reservation)[0];
        if (!reservation || !item) return;

        button.addEventListener('mouseenter', () => {
            clearTimeout(reservationCalendarTooltipTimer);
            reservationCalendarTooltipTimer = setTimeout(() => {
                showReservationCalendarTooltip(button, reservation, item);
            }, 100);
        });
        button.addEventListener('mouseleave', hideReservationCalendarTooltip);
        button.addEventListener('focus', () => showReservationCalendarTooltip(button, reservation, item));
        button.addEventListener('blur', hideReservationCalendarTooltip);
    });
}

function bindReservationCalendarCreateSlots(calendar) {
    calendar.querySelectorAll('.calendar-create-slot, .therapist-create-slot').forEach((button) => {
        button.addEventListener('click', () => {
            openReservationForm({
                date: button.dataset.date,
                startTime: button.dataset.time,
                employeeId: button.dataset.employeeId,
            });
        });
    });
}

function isAlreadyPaid(reservation) {
    return Boolean(
        reservation?.is_paid
        || reservation?.transaction_id
        || reservation?.transaction?.id
        || reservation?.transaction_status === 'paid',
    );
}

// Pembayaran dan pengerjaan treatment adalah dua hal yang berbeda. Kalender
// menampilkan keduanya tanpa mengubah status layanan saat kasir menutup tagihan.
function reservationCalendarStatus(reservation) {
    return isAlreadyPaid(reservation) ? 'paid' : reservationStatus(reservation);
}

function statusClass(status) {
    if (status === 'in_service') return 'serving';
    if (status === 'arrived' || status === 'waiting_payment') return 'arrived';
    return '';
}

function openPage(id) {
    const pageId = ({ reservasi: 'reservasi-antrean', keuangan: 'keuangan-arus-kas' })[id] || id;
    const navigationPage = pageId === 'stok-opname' ? 'stok' : pageId;
    const nav = document.querySelector(`#navigation [data-page="${navigationPage}"]`);
    const page = document.getElementById(pageId);
    if (!nav || !page) return;

    document.querySelectorAll('.page').forEach((element) => element.classList.remove('active'));
    page.classList.add('active');
    document.querySelectorAll('#navigation [data-page]').forEach((element) => {
        element.classList.toggle('active', element.dataset.page === navigationPage);
    });
    document.querySelectorAll('#navigation details').forEach((details) => {
        const containsActivePage = Boolean(details.querySelector('[data-page].active'));
        details.querySelector(':scope > summary')?.classList.toggle('active', containsActivePage);
        if (containsActivePage) details.open = true;
    });
    const pageCopy = copy[pageId] || copy[navigationPage];
    document.getElementById('page-title').textContent = pageCopy[0];
    document.getElementById('page-subtitle').textContent = pageCopy[1];
    history.replaceState(null, '', `#${pageId}`);
    if (pageId === 'reservasi-antrean') {
        const date = document.getElementById('reservation-calendar-date');
        if (date) date.value = localDate();
        renderReservations();
    }
    scrollTo(0, 0);
}

function openDashboardMetric(card) {
    const target = card.dataset.target;
    if (!target) return;
    openPage(target);

    if (target === 'reservasi-antrean') {
        const filters = document.querySelectorAll('#reservasi-kalender .filters input,#reservasi-kalender .filters select');
        const requestedStatus = card.dataset.reservationStatus || '';
        reservationMode = 'today';
        reservationStatusGroup = requestedStatus === 'arrived' ? 'arrived' : null;
        if (filters?.[0]) filters[0].value = localDate();
        if (filters?.[2]) filters[2].value = reservationStatusGroup ? '' : requestedStatus;
        renderReservations();
    }

    if (target === 'stok') {
        document.getElementById('stock-list')?.scrollIntoView({ block: 'start' });
    }
}

function renderReservations() {
    hideReservationCalendarTooltip();
    const all = [...array(state.reservations)].sort((left, right) => (
        reservationDate(left).localeCompare(reservationDate(right))
        || reservationTime(left).localeCompare(reservationTime(right))
        || Number(left.id) - Number(right.id)
    ));
    const today = localDate();
    const selectedDate = document.getElementById('reservation-calendar-date')?.value || today;
    const selectedEmployee = Number(document.getElementById('reservation-filter-employee')?.value || 0);
    const selectedStatus = document.getElementById('reservation-filter-status')?.value || '';
    const selected = new Date(`${selectedDate}T12:00:00`);
    const weekStart = new Date(selected);
    weekStart.setDate(selected.getDate() - ((selected.getDay() + 6) % 7));
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);
    const dateKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const weekStartKey = dateKey(weekStart);
    const weekEndKey = dateKey(weekEnd);

    let rows = all.filter((reservation) => {
        const date = reservationDate(reservation);
        return reservationStatus(reservation) !== 'cancelled'
            && date >= weekStartKey
            && date <= weekEndKey;
    });

    if (selectedEmployee) {
        rows = rows.filter((reservation) => reservationStaffIds(reservation).includes(selectedEmployee));
    }
    if (reservationStatusGroup === 'arrived') {
        rows = rows.filter((reservation) => ['arrived', 'in_service', 'waiting_payment', 'completed'].includes(reservationStatus(reservation)));
    } else if (selectedStatus) {
        rows = rows.filter((reservation) => reservationStatus(reservation) === selectedStatus);
    }

    const todayRows = all.filter((reservation) => (
        reservationStatus(reservation) !== 'cancelled'
        && reservationDate(reservation) === selectedDate
    ));
    const short = document.getElementById('queue-short');
    if (short) {
        short.innerHTML = todayRows.slice(0, 5).map((reservation) => {
            const status = reservationStatus(reservation);
            return `<div class="queue-row">
                <strong class="queue-code">${escapeHtml(reservation.queue_number || reservation.booking_code)}</strong>
                <time class="queue-time">${escapeHtml(reservationTime(reservation))}</time>
                <span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTreatmentSummary(reservation))} · ${escapeHtml(reservationStaffSummary(reservation))}</small></span>
                <em class="queue-status ${statusClass(status)}">${escapeHtml(statusLabel(status))}</em>
                <span class="material-symbols-outlined">chevron_right</span>
            </div>`;
        }).join('') || '<p class="empty-state">Belum ada reservasi hari ini.</p>';
    }

    const period = document.getElementById('calendar-period-label');
    if (period) {
        const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        period.textContent = `${dateFormat.format(weekStart)} – ${dateFormat.format(weekEnd)}`;
    }

    const calendar = document.getElementById('reservation-calendar');
    if (calendar) {
        const openingMinutes = 9 * 60;
        const closingMinutes = 22 * 60;
        const visibleMinutes = closingMinutes - openingMinutes;
        const dayFormat = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
        const slots = Array.from({ length: visibleMinutes / 30 }, (_, index) => index);
        const headers = Array.from({ length: 7 }, (_, index) => {
            const day = new Date(weekStart);
            day.setDate(weekStart.getDate() + index);
            const active = dateKey(day) === today ? ' is-today' : '';
            const selectedDay = dateKey(day) === selectedDate ? ' is-selected' : '';
            return `<button type="button" class="calendar-day-head calendar-day-open${active}${selectedDay}" data-date="${dateKey(day)}">${escapeHtml(dayFormat.format(day))}</button>`;
        }).join('');
        const timeColumn = slots.map((slot) => {
            const hour = 9 + Math.floor(slot / 2);
            return `<div class="calendar-hour">${slot % 2 === 0 ? `${String(hour).padStart(2, '0')}.00` : ''}</div>`;
        }).join('');
        const dayColumns = Array.from({ length: 7 }, () => `<div class="calendar-day-column">${slots.map((slot) => `<div class="calendar-slot ${slot % 2 === 0 ? 'is-half-hour' : 'is-hour'}"></div>`).join('')}</div>`).join('');
        const createSlots = canCreateReservations ? Array.from({ length: 7 }, (_, dayIndex) => slots.map((slot) => {
            const day = new Date(weekStart);
            day.setDate(weekStart.getDate() + dayIndex);
            const minutes = openingMinutes + (slot * 30);
            const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
            const label = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long' }).format(day);
            return `<button type="button" class="calendar-create-slot" data-date="${dateKey(day)}" data-time="${time}" aria-label="Buat reservasi ${escapeHtml(label)} pukul ${time}" style="grid-column:${dayIndex + 1};grid-row:${slot + 1}"></button>`;
        }).join('')).join('') : '';
        const calendarReservations = rows.flatMap((reservation) => reservationItems(reservation).map((item, itemIndex) => {
            if (selectedEmployee) {
                const assigned = itemStaff(item).some((staff) => Number(
                    staff.employee_id ?? staff.employee?.id ?? staff.id,
                ) === selectedEmployee);
                if (!assigned) return null;
            }

            const date = reservationItemDate(item, reservation);
            const day = Math.round((new Date(`${date}T12:00:00`) - weekStart) / 86400000);
            const timing = reservationItemTiming(item, reservation);
            const start = Math.max(openingMinutes, timing.startMinutes);
            const end = Math.min(closingMinutes, timing.endMinutes);
            if (day < 0 || day > 6 || end <= openingMinutes || start >= closingMinutes || end <= start) return null;

            return { reservation, item, itemIndex, timing, day, start, end };
        })).filter(Boolean);

        // Ringkasan mingguan mempertahankan maksimal dua kartu pada waktu yang sama.
        // Sisanya menjadi indikator yang membuka tampilan harian per therapist.
        const positionedReservations = [];
        const overflowGroups = [];
        Array.from({ length: 7 }, (_, day) => day).forEach((day) => {
            const dayReservations = calendarReservations
                .filter((entry) => entry.day === day)
                .sort((left, right) => left.start - right.start || right.end - left.end || Number(left.reservation.id) - Number(right.reservation.id));
            let group = [];
            let groupEnd = 0;
            const positionGroup = () => {
                if (!group.length) return;
                const laneEnds = [];
                const positionedGroup = group.map((entry) => {
                    let lane = laneEnds.findIndex((laneEnd) => laneEnd <= entry.start);
                    if (lane === -1) {
                        lane = laneEnds.length;
                        laneEnds.push(entry.end);
                    } else {
                        laneEnds[lane] = entry.end;
                    }
                    return { ...entry, lane };
                });
                const lanes = laneEnds.length;
                positionedGroup
                    .filter((entry) => lanes <= 2 || entry.lane < 2)
                    .forEach((entry) => positionedReservations.push({ ...entry, lanes: Math.min(lanes, 2) }));
                const hidden = positionedGroup.filter((entry) => entry.lane >= 2);
                if (hidden.length) {
                    overflowGroups.push({ day, start: Math.min(...hidden.map((entry) => entry.start)), count: hidden.length });
                }
                group = [];
                groupEnd = 0;
            };

            dayReservations.forEach((entry) => {
                if (group.length && entry.start >= groupEnd) positionGroup();
                group.push(entry);
                groupEnd = Math.max(groupEnd, entry.end);
            });
            positionGroup();
        });

        const weeklyEvents = positionedReservations.map(({ reservation, item, itemIndex, timing, day, start, end, lane, lanes }) => {
            const serviceStatus = reservationStatus(reservation);
            const status = reservationCalendarStatus(reservation);
            const paymentLabel = isAlreadyPaid(reservation) ? 'Lunas' : 'Belum dibayar';
            const dayWidth = 100 / 7;
            const width = dayWidth / lanes;
            const left = (day * dayWidth) + (lane * width);
            const top = ((start - openingMinutes) / visibleMinutes) * 100;
            const height = ((end - start) / visibleMinutes) * 100;
            const compact = (end - start) <= 30 || lanes > 1 ? ' is-compact' : '';
            const staff = itemStaff(item).map(employeeName).join(', ') || '-';
            const ariaLabel = `${timing.startLabel} sampai ${timing.endLabel}, ${reservationCustomerName(reservation)}, ${itemTreatmentName(item)}, therapist ${staff}, pembayaran ${paymentLabel}, layanan ${statusLabel(serviceStatus)}`;
            return `<button type="button" class="calendar-event ${statusClass(status)} status-${escapeHtml(status)} reservation-detail${compact}" data-id="${Number(reservation.id)}" data-item-index="${itemIndex}" aria-label="${escapeHtml(ariaLabel)}" style="top:calc(${top}% + 1px);height:calc(${height}% - 2px);left:calc(${left}% + 2px);width:calc(${width}% - 4px)">
                <span class="calendar-event-main"><time>${escapeHtml(timing.startLabel)}</time><b>${escapeHtml(reservationCustomerName(reservation))}</b></span>
                <small>${escapeHtml(itemTreatmentName(item))}</small>
            </button>`;
        }).join('');
        const overflowIndicators = overflowGroups.map(({ day, start, count }) => {
            const top = ((start - openingMinutes) / visibleMinutes) * 100;
            const date = new Date(weekStart);
            date.setDate(weekStart.getDate() + day);
            return `<button type="button" class="calendar-overflow" data-date="${dateKey(date)}" style="top:calc(${top}% + 2px);left:calc(${(day * 100) / 7}% + 4px)">+${count} jadwal</button>`;
        }).join('');

        if (calendarMode === 'week') {
            calendar.setAttribute('aria-label', 'Ringkasan kalender reservasi mingguan');
            calendar.innerHTML = `<div class="calendar-week-hint">Tampilkan maksimal dua jadwal yang bertumpuk. Klik tanggal atau <b>+N jadwal</b> untuk melihat kolom therapist secara penuh.</div><div class="calendar-grid"><div class="calendar-header"><div class="calendar-corner" aria-hidden="true"></div>${headers}</div><div class="calendar-body"><div class="calendar-time-column">${timeColumn}<span class="calendar-close-time">22.00</span></div>${dayColumns}<div class="calendar-events"><div class="calendar-empty-slots">${createSlots}</div>${weeklyEvents}${overflowIndicators}</div></div></div>`;
        } else {
            const therapists = selectedEmployee
                ? serviceProviders().filter((employee) => Number(employee.id) === selectedEmployee)
                : serviceProviders();
            const dailyTherapists = therapists.length ? therapists : serviceProviders();
            const dayRows = calendarReservations.filter((entry) => reservationItemDate(entry.item, entry.reservation) === selectedDate);
            const offTherapistIds = therapistAttendanceDate === selectedDate
                ? therapistAttendance.filter((therapist) => therapist.status === 'off').map((therapist) => Number(therapist.employee_id))
                : [];
            const availableSlots = (employeeId) => {
                if (offTherapistIds.includes(Number(employeeId))) return [];
                const occupied = dayRows
                    .filter(({ item }) => itemStaff(item).some((staff) => Number(staff.employee_id ?? staff.employee?.id ?? staff.id) === Number(employeeId)))
                    .map(({ reservation, item, start }) => [start, clockMinutes(itemReadyTime(item, reservation)) ?? start])
                    .sort((left, right) => left[0] - right[0]);
                let cursor = openingMinutes;
                const slots = [];
                occupied.forEach(([start, end]) => {
                    if (start > cursor) slots.push([cursor, Math.min(start, openingMinutes + visibleMinutes)]);
                    cursor = Math.max(cursor, end);
                });
                if (cursor < openingMinutes + visibleMinutes) slots.push([cursor, openingMinutes + visibleMinutes]);
                const format = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                return slots.filter(([, end]) => end > openingMinutes).map(([start, end]) => `${format(start)}–${format(end)} (${end - start}m)`);
            };
            const dailyHeaders = dailyTherapists.map((employee, index) => {
                const isOff = offTherapistIds.includes(Number(employee.id));
                return `<div class="therapist-day-head${isOff ? ' is-off' : ''}" style="grid-column:${index + 2}" aria-label="${escapeHtml(employee.name)}${isOff ? ', libur' : ''}"><b>${escapeHtml(employee.name)}</b></div>`;
            }).join('');
            const dailyTimes = slots.map((slot) => {
                const minutes = openingMinutes + (slot * 30);
                const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                return `<div class="therapist-day-time" style="grid-column:1;grid-row:${slot + 2}">${slot % 2 === 0 ? time.replace(':', '.') : ''}</div>`;
            }).join('');
            const dailySlots = dailyTherapists.flatMap((employee, index) => slots.map((slot) => {
                const minutes = openingMinutes + (slot * 30);
                const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                const isOff = offTherapistIds.includes(Number(employee.id));
                if (isOff) return `<div class="therapist-off-slot" aria-hidden="true" style="grid-column:${index + 2};grid-row:${slot + 2}"></div>`;
                if (!canCreateReservations) return `<div class="therapist-grid-slot" aria-hidden="true" style="grid-column:${index + 2};grid-row:${slot + 2}"></div>`;
                return `<button type="button" class="therapist-create-slot" data-date="${selectedDate}" data-time="${time}" data-employee-id="${Number(employee.id)}" aria-label="Tambah reservasi ${escapeHtml(employee.name)}, ${time}" style="grid-column:${index + 2};grid-row:${slot + 2}"></button>`;
            })).join('');
            const offColumnShades = dailyTherapists.map((employee, index) => (
                offTherapistIds.includes(Number(employee.id))
                    ? `<div class="therapist-off-column" aria-hidden="true" style="grid-column:${index + 2};grid-row:2 / -1"></div>`
                    : ''
            )).join('');
            const dailyEvents = dayRows.flatMap(({ reservation, item, itemIndex, timing, start, end }) => {
                const staff = itemStaff(item);
                return staff.map((assignment) => {
                    const employeeId = Number(assignment.employee_id ?? assignment.employee?.id ?? assignment.id);
                    const therapistIndex = dailyTherapists.findIndex((employee) => Number(employee.id) === employeeId);
                    if (therapistIndex < 0) return '';
                    const status = reservationCalendarStatus(reservation);
                    const startRow = Math.max(2, Math.floor((start - openingMinutes) / 30) + 2);
                    const readyMinutes = clockMinutes(itemReadyTime(item, reservation)) ?? end;
                    const span = Math.max(1, Math.ceil((readyMinutes - start) / 30));
                    const serviceRatio = Math.min(100, Math.max(0, ((end - start) / Math.max(1, readyMinutes - start)) * 100));
                    const staffName = employeeName(assignment);
                    const ariaLabel = `${timing.startLabel} sampai ${timing.endLabel}, siap lagi ${itemReadyTime(item, reservation)}, ${reservationCustomerName(reservation)}, ${itemTreatmentName(item)}, therapist ${staffName}`;
                    return `<button type="button" class="calendar-event therapist-day-event ${statusClass(status)} status-${escapeHtml(status)} reservation-detail" data-id="${Number(reservation.id)}" data-item-index="${itemIndex}" aria-label="${escapeHtml(ariaLabel)}" style="grid-column:${therapistIndex + 2};grid-row:${startRow} / span ${span};--service-ratio:${serviceRatio}%"><span class="calendar-event-main"><time>${escapeHtml(timing.startLabel)}</time><b>${escapeHtml(reservationCustomerName(reservation))}</b></span><small class="calendar-event-treatment">${escapeHtml(itemTreatmentName(item))}</small><span class="calendar-rest-label">Istirahat · siap lagi ${escapeHtml(itemReadyTime(item, reservation))}</span></button>`;
                });
            }).join('');
            const empty = dailyTherapists.length ? '' : '<p class="empty-state therapist-day-empty">Belum ada therapist aktif untuk ditampilkan.</p>';
            calendar.setAttribute('aria-label', 'Kalender harian per therapist');
            calendar.innerHTML = `<div class="calendar-day-view-head"><div><b>${escapeHtml(new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(selected))}</b><small>Satu kolom adalah satu therapist. Klik <strong>+</strong> pada slot kosong untuk membuat reservasi dengan therapist dan jam sudah terisi.</small></div><button type="button" class="secondary calendar-week-back">← Ringkasan mingguan</button></div>${empty}<div class="therapist-day-calendar" style="--therapist-count:${Math.max(1, dailyTherapists.length)}"><div class="therapist-day-corner">Jam</div>${dailyHeaders}${dailyTimes}${dailySlots}${offColumnShades}${dailyEvents}</div>`;
        }
        bindReservationCalendarTooltips(calendar, all);
        bindReservationCalendarCreateSlots(calendar);
        calendar.querySelectorAll('.calendar-day-open, .calendar-overflow').forEach((button) => {
            button.addEventListener('click', () => {
                const date = document.getElementById('reservation-calendar-date');
                if (date) date.value = button.dataset.date;
                calendarMode = 'day';
                document.querySelectorAll('[data-calendar-mode]').forEach((tab) => tab.classList.toggle('active', tab.dataset.calendarMode === 'day'));
                renderReservations();
            });
        });
        calendar.querySelector('.calendar-week-back')?.addEventListener('click', () => {
            calendarMode = 'week';
            document.querySelectorAll('[data-calendar-mode]').forEach((tab) => tab.classList.toggle('active', tab.dataset.calendarMode === 'week'));
            renderReservations();
        });
    }

    const queue = document.getElementById('reservation-queue-list');
    const queueDate = document.getElementById('today-queue-date');
    if (queueDate) queueDate.textContent = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(selected);
    if (queue) queue.innerHTML = todayRows.map((reservation) => {
        const status = reservationCalendarStatus(reservation);
        return `<button type="button" class="calendar-queue-item reservation-detail" data-id="${Number(reservation.id)}"><time>${escapeHtml(reservationTime(reservation))}</time><span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTreatmentSummary(reservation))}</small><small>${escapeHtml(reservationStaffSummary(reservation))}</small><em class="status-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</em></span></button>`;
    }).join('') || '<p class="empty-state">Belum ada reservasi pada tanggal ini.</p>';

    document.querySelectorAll('.reservation-detail').forEach((button) => {
        button.onclick = () => {
            hideReservationCalendarTooltip();
            const reservation = all.find((item) => Number(item.id) === Number(button.dataset.id));
            if (reservation) openReservationDetail(reservation);
        };
    });
}

function openReservationDetail(reservation) {
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    const items = reservationItems(reservation);
    const paid = isAlreadyPaid(reservation);
    const serviceStatus = reservationStatus(reservation);
    const paymentStatus = paid ? 'Lunas' : 'Belum dibayar';
    wrapper.innerHTML = `<div class="modal-box reservation-modal-box">
        <div class="modal-head">
            <div><h2>Detail ${escapeHtml(reservation.queue_number || reservation.booking_code)}</h2><p>${escapeHtml(reservationCustomerName(reservation))} · ${escapeHtml(reservationDate(reservation))}</p></div>
            <button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="quick-info reservation-summary">
            <p><span>Telepon</span><b>${escapeHtml(reservationPhone(reservation) || '-')}</b></p>
            <p><span>Sumber booking</span><b>${escapeHtml(reservation.source || '-')}</b></p>
            <p><span>Status layanan</span><b>${escapeHtml(statusLabel(serviceStatus))}</b></p>
            <p><span>Pembayaran</span><b class="reservation-payment-status ${paid ? 'paid' : 'unpaid'}">${paymentStatus}</b></p>
            <p><span>Catatan</span><b>${escapeHtml(reservation.general_notes || reservation.notes || '-')}</b></p>
        </div>
        <div class="reservation-detail-items">${items.map((item, index) => {
            const currentStatus = automaticWorkStatus(item, reservation);

            return `<article class="reservation-item-card">
                <div class="reservation-item-title"><strong>${index + 1}. ${escapeHtml(itemTreatmentName(item))}</strong><b>${money(itemPrice(item))}</b></div>
                <div class="reservation-detail-meta">
                    <span>Mulai <b>${escapeHtml(itemStartTime(item, reservation))}</b></span>
                    <span>Selesai <b>${escapeHtml(itemEndTime(item, reservation))}</b></span>
                    <span>Siap lagi <b>${escapeHtml(itemReadyTime(item, reservation))}</b></span>
                    <span>Therapist <b>${escapeHtml(itemStaff(item).map(employeeName).join(', ') || '-')}</b></span>
                </div>
                <div class="reservation-work-status"><span><small>Status otomatis berdasarkan jadwal</small><b class="status-${escapeHtml(currentStatus)}">${escapeHtml(workStatusLabels[currentStatus] || currentStatus)}</b></span><small>Berubah otomatis mengikuti jam mulai, selesai, dan siap therapist.</small></div>
            </article>`;
        }).join('') || '<p class="empty-state">Belum ada treatment.</p>'}</div>
        <footer>${canUpdateReservations && !paid && !['cancelled', 'completed'].includes(reservation.status)
            ? '<button type="button" class="secondary reservation-cancel">Batalkan reservasi</button>'
            : ''}<button type="button" class="primary quick-close">Tutup</button></footer>
    </div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelector('.reservation-cancel')?.addEventListener('click', async () => {
        const confirmed = await confirmAction({
            title: 'Batalkan reservasi?',
            message: 'Reservasi belum dibayar akan dibatalkan. Alasan pembatalan wajib dicatat.',
            confirmLabel: 'Lanjutkan',
            icon: 'cancel',
        });
        if (!confirmed) return;

        quickForm('Alasan pembatalan', [
            ['reason', 'Alasan pembatalan', 'text'],
        ], async (data) => {
            const result = await api(`/operasional/reservasi/${Number(reservation.id)}`, {
                method: 'PATCH',
                body: JSON.stringify({ status: 'cancelled', reason: data.reason }),
            });
            wrapper.remove();
            return result;
        });
    });
}

function resetCashier() {
    selectedReservation = null;
    const receipt = document.getElementById('cashier-receipt');
    receipt?.classList.add('empty');
    if (receipt) receipt.hidden = true;
    document.querySelector('#kasir .cashier-grid')?.classList.add('cashier-awaiting-selection');
    document.getElementById('receipt-number').textContent = '—';
    document.getElementById('receipt-name').textContent = 'Pilih transaksi terlebih dahulu';
    document.querySelector('.receipt .member').textContent = '';
    document.getElementById('receipt-items').innerHTML = '<p class="empty-state">Belum ada transaksi yang dipilih.</p>';
    document.getElementById('subtotal').textContent = money(0);
    document.getElementById('discount-value').textContent = money(0);
    document.getElementById('grand-total').textContent = money(0);
    document.getElementById('payment-total').textContent = money(0);
    document.getElementById('payment-description').textContent = 'Pilih transaksi';
    const customerName = document.getElementById('cashier-customer-name');
    if (customerName) customerName.textContent = 'Belum dipilih';
    document.getElementById('discount').disabled = true;
    document.getElementById('manual-discount').disabled = true;
    document.getElementById('manual-discount').value = '';
    document.getElementById('open-payment').disabled = true;
    document.getElementById('add-extra').disabled = true;
    resetPaymentRows();
}

function selectedDiscount() {
    const manual = Number(document.getElementById('manual-discount')?.value || 0);
    return manual > 0 ? manual : Number(document.getElementById('discount')?.value || 0);
}

function selectedTotal() {
    const reservation = array(state.reservations).find((item) => Number(item.id) === Number(selectedReservation));
    if (!reservation) return 0;
    const serviceSubtotal = reservationSubtotal(reservation);
    const productSubtotal = reservationProductItems(reservation).reduce((sum, item) => sum + (Number(item.unit_price) * Number(item.quantity)), 0);
    return Math.round(serviceSubtotal - (serviceSubtotal * selectedDiscount() / 100) + productSubtotal);
}

function reservationProductItems(reservation) {
    return array(reservation?.product_items);
}

function renderCashier() {
    const rows = array(state.reservations).filter((reservation) => (
        !isAlreadyPaid(reservation)
        && reservationStatus(reservation) !== 'cancelled'
    ));
    const box = document.getElementById('cashier-queue');
    if (!box) return;

    box.innerHTML = rows.map((reservation) => `<button class="cashier-item ${Number(reservation.id) === Number(selectedReservation) ? 'active' : ''}" data-id="${Number(reservation.id)}">
        <strong>${escapeHtml(reservation.queue_number || reservation.booking_code)}</strong>
        <span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTime(reservation))} · ${escapeHtml(reservationTreatmentSummary(reservation))}</small></span>
        <i class="material-symbols-outlined row-action">chevron_right</i>
    </button>`).join('') || `<div class="cashier-empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span>
        <h4>Belum ada transaksi aktif</h4>
        <p>Buat transaksi walk-in, lalu data pelanggan dan treatment akan langsung dibuka di kasir.</p>
        <button type="button" class="secondary cashier-create-transaction"><span class="material-symbols-outlined" aria-hidden="true">add</span> Buat transaksi walk-in</button>
    </div>`;

    document.querySelectorAll('.cashier-item').forEach((button) => {
        button.onclick = () => selectCashier(Number(button.dataset.id));
    });

    if (selectedReservation && rows.some((item) => Number(item.id) === Number(selectedReservation))) {
        selectCashier(Number(selectedReservation));
    } else {
        resetCashier();
    }
}

function selectCashier(id) {
    if (Number(selectedReservation) !== Number(id)) {
        document.getElementById('manual-discount').value = '';
    }
    selectedReservation = id;
    document.querySelectorAll('.cashier-item').forEach((element) => {
        element.classList.toggle('active', Number(element.dataset.id) === Number(id));
    });

    const reservation = array(state.reservations).find((item) => Number(item.id) === Number(id));
    if (!reservation) {
        resetCashier();
        return;
    }

    const discountSelect = document.getElementById('discount');
    if (!reservation.is_member && discountSelect) discountSelect.value = '0';
    const items = reservationItems(reservation).filter((item) => item.work_status !== 'cancelled');
    const serviceSubtotal = reservationSubtotal(reservation);
    const productItems = reservationProductItems(reservation);
    const productSubtotal = productItems.reduce((sum, item) => sum + (Number(item.unit_price) * Number(item.quantity)), 0);
    const subtotal = serviceSubtotal + productSubtotal;
    const discount = selectedDiscount();
    const discountAmount = Math.round(serviceSubtotal * discount / 100);
    const total = subtotal - discountAmount;

    const receipt = document.getElementById('cashier-receipt');
    receipt?.classList.remove('empty');
    if (receipt) receipt.hidden = false;
    document.querySelector('#kasir .cashier-grid')?.classList.remove('cashier-awaiting-selection');
    document.getElementById('receipt-number').textContent = reservation.queue_number || reservation.booking_code;
    document.getElementById('receipt-name').textContent = reservationCustomerName(reservation);
    const customerName = document.getElementById('cashier-customer-name');
    if (customerName) customerName.textContent = reservationCustomerName(reservation);
    document.querySelector('.receipt .member').textContent = reservation.is_member ? '· MEMBER' : '· NON-MEMBER';
    const treatmentLines = items.map((item) => `<div class="receipt-line">
        <i class="material-symbols-outlined">spa</i>
        <span><b>${escapeHtml(itemTreatmentName(item))}</b><small>Therapist: ${escapeHtml(itemStaff(item).map(employeeName).join(', ') || '-')}</small></span>
        <strong>${money(itemPrice(item))}</strong>
    </div>`).join('');
    const productLines = productItems.map((item) => `<div class="receipt-line receipt-product-line">
        <i class="material-symbols-outlined">inventory_2</i>
        <span><b>${escapeHtml(item.name)}</b><small>${Number(item.quantity)} ${escapeHtml(item.unit || 'pcs')} × ${money(item.unit_price)}</small></span>
        <strong>${money(Number(item.unit_price) * Number(item.quantity))}</strong>
        <button type="button" class="link remove-cashier-product" data-id="${Number(item.product_id)}" aria-label="Hapus produk"><span class="material-symbols-outlined">close</span></button>
    </div>`).join('');
    document.getElementById('receipt-items').innerHTML = treatmentLines + productLines;
    document.getElementById('discount').disabled = false;
    document.getElementById('manual-discount').disabled = false;
    document.getElementById('open-payment').disabled = false;
    document.getElementById('add-extra').disabled = !array(state.products).some((product) => (
        Number(product.is_active ?? 1) === 1 && productStock(product) > 0
    ));
    document.getElementById('subtotal').textContent = money(subtotal);
    document.getElementById('discount-value').textContent = `-${money(discountAmount)}`;
    document.getElementById('grand-total').textContent = money(total);
    document.getElementById('payment-total').textContent = money(total);
    document.getElementById('payment-description').textContent = `${reservation.queue_number || reservation.booking_code} · ${reservationCustomerName(reservation)}`;
    resetPaymentRows();
}

function openCashierProductPicker() {
    if (!selectedReservation) {
        toast('Pilih antrean terlebih dahulu.', true);
        return;
    }

    const products = array(state.products).filter((product) => (
        Number(product.is_active ?? 1) === 1 && productStock(product) > 0
    ));
    const sellableProducts = products.filter((product) => Number(product.selling_price || 0) > 0);
    if (!products.length) {
        toast('Tidak ada produk aktif dengan stok yang tersedia.', true);
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><div><h2>Tambah produk</h2><p>Pilih produk dari stok yang tersedia.</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div><form><div class="quick-fields"><label>Produk<select name="product_id">${products.map((product) => `<option value="${Number(product.id)}">${escapeHtml(product.name)} · ${money(product.selling_price)}</option>`).join('')}</select></label><label>Jumlah<input name="quantity" type="number" min="1" step="0.0001" value="1" required></label><p class="product-picker-stock" id="product-picker-stock"></p></div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary">Tambah</button></footer></form></div>`;
    document.body.appendChild(wrapper);
    const select = wrapper.querySelector('select[name="product_id"]');
    const quantity = wrapper.querySelector('input[name="quantity"]');
    const stockLabel = wrapper.querySelector('#product-picker-stock');
    const submitButton = wrapper.querySelector('button.primary');
    select.innerHTML = products.map((product) => {
        const sellable = Number(product.selling_price || 0) > 0;
        return `<option value="${Number(product.id)}" ${sellable ? '' : 'disabled'}>${escapeHtml(product.name)} · ${sellable ? money(product.selling_price) : 'Harga jual belum diatur'}</option>`;
    }).join('');
    submitButton.disabled = !sellableProducts.length;
    const syncStock = () => {
        const product = products.find((item) => Number(item.id) === Number(select.value));
        stockLabel.textContent = product ? `Stok tersedia: ${productStock(product)} ${productUnit(product)} · Harga jual: ${money(product.selling_price)}` : '';
        quantity.max = product ? productStock(product) : '';
        if (product && Number(product.selling_price || 0) <= 0) {
            stockLabel.textContent = `Stok tersedia: ${productStock(product)} ${productUnit(product)} · Harga jual belum diatur oleh admin.`;
        }
        if (!sellableProducts.length) {
            stockLabel.textContent = 'Semua produk memiliki stok, tetapi harga jualnya belum diatur oleh admin.';
        }
    };
    syncStock();
    select.onchange = syncStock;
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelector('form').onsubmit = (event) => {
        event.preventDefault();
        const product = products.find((item) => Number(item.id) === Number(select.value));
        const amount = Number(quantity.value || 0);
        if (!product || Number(product.selling_price || 0) <= 0) {
            toast('Produk ini belum memiliki harga jual. Minta Admin untuk mengaturnya terlebih dahulu.', true);
            return;
        }
        if (!product || amount <= 0 || amount > productStock(product)) {
            toast('Jumlah produk melebihi stok yang tersedia.', true);
            return;
        }
        submitButton.disabled = true;
        api(`/operasional/reservasi/${Number(selectedReservation)}/produk`, {
            method: 'POST',
            body: JSON.stringify({ product_id: Number(product.id), quantity: String(amount) }),
        }).then(async (result) => {
            wrapper.remove();
            toast(result.message);
            await refresh();
            selectCashier(selectedReservation);
        }).catch((error) => {
            submitButton.disabled = false;
            toast(error.message, true);
        });
    };
}

function openCashierAddPicker() {
    if (!selectedReservation) {
        toast('Pilih antrean terlebih dahulu.', true);
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><div><h2>Tambahkan ke transaksi</h2><p>Pilih jenis tambahan sebelum pembayaran.</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div><div class="cashier-add-choices"><button type="button" class="cashier-add-choice" data-add-type="product"><i class="material-symbols-outlined">inventory_2</i><span><b>Produk</b><small>Jual produk retail atau add-on.</small></span></button><button type="button" class="cashier-add-choice" data-add-type="treatment"><i class="material-symbols-outlined">spa</i><span><b>Treatment</b><small>Pilih layanan, jam, dan therapist.</small></span></button></div></div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelector('[data-add-type="product"]').onclick = () => {
        wrapper.remove();
        openCashierProductPicker();
    };
    wrapper.querySelector('[data-add-type="treatment"]').onclick = () => {
        wrapper.remove();
        openCashierTreatmentPicker();
    };
}

function openCashierTreatmentPicker() {
    const reservation = array(state.reservations).find((item) => Number(item.id) === Number(selectedReservation));
    const treatments = array(state.treatments).filter((treatment) => Number(treatment.is_active ?? 1) === 1);
    if (!reservation || !treatments.length) {
        toast('Tidak ada treatment aktif yang dapat ditambahkan.', true);
        return;
    }

    const defaultTime = String(reservation.reservation_time || '09:00').slice(0, 5);
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><div><h2>Tambah treatment</h2><p>Masuk ke jadwal reservasi dan invoice sebelum pembayaran.</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div><form><div class="quick-fields"><label>Treatment<select name="treatment_id">${treatments.map((treatment) => `<option value="${Number(treatment.id)}">${escapeHtml(treatment.name)} · ${money(treatmentPrice(treatment))}</option>`).join('')}</select></label><label>Jam mulai<select name="start_time">${reservationTimeOptions(defaultTime)}</select></label><label class="treatment-therapist-field">Therapist<select name="employee_id" required><option value="">Memuat therapist...</option></select></label><p class="cashier-treatment-availability" aria-live="polite">Memeriksa jadwal therapist…</p></div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary" disabled>Tambahkan</button></footer></form></div>`;
    document.body.appendChild(wrapper);
    const treatmentSelect = wrapper.querySelector('[name="treatment_id"]');
    const timeSelect = wrapper.querySelector('[name="start_time"]');
    const employeeSelect = wrapper.querySelector('[name="employee_id"]');
    const availability = wrapper.querySelector('.cashier-treatment-availability');
    const submitButton = wrapper.querySelector('button.primary');
    const syncSubmitState = () => {
        submitButton.disabled = employeeSelect.disabled || !employeeSelect.value;
    };

    const loadAvailability = async () => {
        employeeSelect.disabled = true;
        submitButton.disabled = true;
        availability.textContent = 'Memeriksa jadwal therapist…';
        try {
            const data = await api(`/operasional/reservasi/terapis-tersedia?date=${encodeURIComponent(reservation.reservation_date)}&start_time=${encodeURIComponent(timeSelect.value)}&treatment_id=${encodeURIComponent(treatmentSelect.value)}`);
            const available = array(data.employees).filter((employee) => employee.available);
            employeeSelect.innerHTML = available.length
                ? `<option value="">Pilih therapist</option>${available.map((employee) => `<option value="${Number(employee.id)}">${escapeHtml(employee.name)}${employee.specialty ? ` · ${escapeHtml(employee.specialty)}` : ''}</option>`).join('')}`
                : '<option value="">Tidak ada therapist tersedia</option>';
            employeeSelect.disabled = !available.length;
            syncSubmitState();
            availability.textContent = available.length
                ? `${available.length} therapist tersedia untuk ${timeSelect.value}.`
                : `Tidak ada therapist tersedia pada ${timeSelect.value}. Coba jam lain.`;
        } catch (error) {
            employeeSelect.innerHTML = '<option value="">Jadwal tidak dapat dimuat</option>';
            employeeSelect.disabled = true;
            syncSubmitState();
            availability.textContent = error.message;
            toast(error.message, true);
        }
    };

    treatmentSelect.addEventListener('change', loadAvailability);
    timeSelect.addEventListener('change', loadAvailability);
    employeeSelect.addEventListener('change', syncSubmitState);
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelector('form').onsubmit = async (event) => {
        event.preventDefault();
        if (!employeeSelect.value) {
            toast('Pilih therapist yang tersedia.', true);
            return;
        }
        submitButton.disabled = true;
        try {
            const result = await api(`/operasional/reservasi/${Number(reservation.id)}/item`, {
                method: 'POST',
                body: JSON.stringify({
                    treatment_id: Number(treatmentSelect.value),
                    start_time: timeSelect.value,
                    staff: [{ employee_id: Number(employeeSelect.value), role: 'primary' }],
                }),
            });
            wrapper.remove();
            toast(result.message);
            await refresh();
            selectCashier(reservation.id);
        } catch (error) {
            submitButton.disabled = false;
            toast(error.message, true);
            if (error.status === 409) loadAvailability();
        }
    };
    loadAvailability();
}

function receiptPayload(result, reservation, productItems, payments) {
    const treatments = reservationItems(reservation)
        .filter((item) => item.work_status !== 'cancelled')
        .map((item) => ({
            type: 'Treatment',
            name: itemTreatmentName(item),
            detail: `Therapist: ${itemStaff(item).map(employeeName).join(', ') || '-'}`,
            quantity: 1,
            unitPrice: itemPrice(item),
            total: itemPrice(item),
        }));
    const products = productItems.map((item) => ({
        type: 'Produk',
        name: item.name,
        detail: `${Number(item.quantity)} ${item.unit || 'pcs'} × ${money(item.unit_price)}`,
        quantity: Number(item.quantity),
        unitPrice: Number(item.unit_price),
        total: Number(item.quantity) * Number(item.unit_price),
    }));
    const serviceSubtotal = reservationSubtotal(reservation);
    const subtotal = serviceSubtotal + products.reduce((total, item) => total + item.total, 0);
    const discount = Math.round(serviceSubtotal * selectedDiscount() / 100);

    return {
        number: compactInvoiceNumber(result.number || result.transaction_number),
        customer: reservationCustomerName(reservation),
        queue: reservation.queue_number || reservation.booking_code,
        date: new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }),
        therapists: [...new Set(reservationItems(reservation)
            .filter((item) => item.work_status !== 'cancelled')
            .flatMap((item) => itemStaff(item).map(employeeName))
            .filter(Boolean))],
        items: [...treatments, ...products],
        payments: payments.map((payment) => {
            const method = paymentMethods().find((item) => Number(item.id) === Number(payment.payment_method_id));
            const chargeAmount = Number(payment.charge_amount || 0);
            return {
                name: method?.name || 'Pembayaran',
                isCash: Boolean(Number(method?.is_cash ?? 0)),
                amount: Number(payment.amount) + chargeAmount,
                baseAmount: Number(payment.amount),
                chargeAmount,
                chargePercent: Number(payment.charge_percent || 0),
                tenderedAmount: Number(payment.tendered_amount || Number(payment.amount) + chargeAmount),
                reference: payment.reference_number,
            };
        }),
        subtotal,
        discount,
        baseTotal: subtotal - discount,
        paymentCharge: payments.reduce((total, payment) => total + Number(payment.charge_amount || 0), 0),
        total: Number(result.total),
        change: Number(result.change_amount || 0),
        cashier: result.cashier_name || 'Kasir Selesa',
    };
}

function legacyPrintReceipt(receipt, format) {
    const compact = format === 'struk';
    const lines = receipt.items.map((item) => `<tr><td><b>${escapeHtml(item.name)}</b>${compact ? '' : `<small>${escapeHtml(item.type)} · ${escapeHtml(item.detail)}</small>`}</td><td class="amount">${compact ? `${item.quantity}×` : money(item.total)}</td></tr>`).join('');
    const paymentLines = receipt.payments.map((payment) => `<p>${escapeHtml(payment.name)}${payment.reference ? ` · ${escapeHtml(payment.reference)}` : ''}<b>${money(payment.amount)}</b></p>`).join('');
    const layout = compact ? 'thermal' : 'invoice';
    const title = compact ? 'STRUK PEMBAYARAN' : 'NOTA PEMBAYARAN';
    const documentWindow = window.open('', '_blank', 'width=980,height=900');
    if (!documentWindow) {
        toast('Popup cetak diblokir browser. Izinkan popup lalu coba lagi.', true);
        return;
    }
    documentWindow.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${escapeHtml(receipt.number)}</title><style>@page{size:${compact ? '80mm auto' : 'A4'};margin:${compact ? '5mm' : '16mm'}}*{box-sizing:border-box}body{margin:0;font:12px Arial,sans-serif;color:#27231f}.sheet{width:${compact ? '70mm' : '100%'};margin:0 auto}.head{text-align:center;border-bottom:1px dashed #777;padding-bottom:10px}.head h1{margin:0;font-size:22px;letter-spacing:1px}.head h2{margin:6px 0 0;font-size:12px}.meta{margin:12px 0;color:#56504b;font-size:11px;line-height:1.55}.meta b{color:#27231f}table{width:100%;border-collapse:collapse}td{padding:7px 0;border-bottom:1px solid #e8e3df;vertical-align:top}td b,td small{display:block}td small{margin-top:3px;color:#706963;font-size:10px}.amount{text-align:right;white-space:nowrap}.total{margin-top:11px;border-top:1px solid #444;padding-top:8px}.total p,.payments p{display:flex;justify-content:space-between;gap:12px;margin:6px 0}.grand{font-size:16px;font-weight:700}.footer{margin-top:16px;padding-top:10px;border-top:1px dashed #777;text-align:center;color:#706963;font-size:10px}@media print{button{display:none}}</style></head><body><main class="sheet ${layout}"><header class="head"><h1>selesa</h1><h2>${title}</h2></header><section class="meta"><div><b>${escapeHtml(receipt.number)}</b></div><div>${escapeHtml(receipt.date)}</div><div>Antrean: ${escapeHtml(receipt.queue)}</div><div>Pelanggan: ${escapeHtml(receipt.customer)}</div></section><table><tbody>${lines}</tbody></table><section class="total"><p><span>Subtotal</span><b>${money(receipt.subtotal)}</b></p>${receipt.discount ? `<p><span>Diskon member</span><b>-${money(receipt.discount)}</b></p>` : ''}<p class="grand"><span>Total</span><b>${money(receipt.total)}</b></p></section><section class="payments">${paymentLines}</section><footer class="footer">Terima kasih telah berkunjung ke Selesa Salon.</footer></main><script>window.addEventListener('load',()=>window.print());<\/script></body></html>`);
    documentWindow.document.close();
}

function compactReceiptPrintLegacy(receipt, format) {
    const compact = format === 'struk';
    const lines = receipt.items.map((item) => `<tr><td><b>${escapeHtml(item.name)}</b>${compact ? `<small>${escapeHtml(String(item.quantity))} &times; ${money(item.unitPrice)}</small>` : `<small>${escapeHtml(item.type)} · ${escapeHtml(item.detail)}</small>`}</td><td class="amount">${money(item.total)}</td></tr>`).join('');
    const paymentLines = receipt.payments.map((payment) => {
        if (payment.isCash) return `<p><span>Tunai</span><b>${money(payment.tenderedAmount)}</b></p>`;
        return `<p><span>${escapeHtml(payment.name)}${payment.reference ? `<small>${escapeHtml(payment.reference)}</small>` : ''}</span><b>${money(payment.amount)}</b></p>`;
    }).join('');
    const paymentSummary = compact
        ? `${paymentLines}${receipt.change > 0 ? `<p class="change"><span>Kembalian</span><b>${money(receipt.change)}</b></p>` : ''}`
        : receipt.payments.map((payment) => `<p><span>${escapeHtml(payment.name)}${payment.reference ? ` · ${escapeHtml(payment.reference)}` : ''}</span><b>${money(payment.amount)}</b></p>`).join('');
    const documentWindow = window.open('', '_blank', 'width=760,height=860');
    if (!documentWindow) {
        toast('Popup cetak diblokir browser. Izinkan popup lalu coba lagi.', true);
        return;
    }

    const logoUrl = `${window.location.origin}/images/selesa-logo.png`;
    documentWindow.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${escapeHtml(receipt.number)}</title><style>@page{size:${compact ? '80mm auto' : 'A4'};margin:${compact ? '4mm' : '16mm'}}*{box-sizing:border-box}body{margin:0;font:12px Arial,sans-serif;color:#27231f}.sheet{width:${compact ? '72mm' : '100%'};margin:0 auto}.head{text-align:center;border-bottom:1px dashed #777;padding-bottom:9px}.head img{display:block;width:${compact ? '31mm' : '52mm'};height:auto;margin:0 auto 7px}.head h1{margin:0;font-size:15px;letter-spacing:.5px}.head h2{margin:3px 0 0;font-size:11px;font-weight:600}.receipt-number{margin-top:4px;font-size:11px;font-weight:700}.meta{margin:11px 0;padding:9px 0;border-bottom:1px dashed #777;color:#403b37;font-size:11px;line-height:1.5}.meta p{display:flex;justify-content:space-between;gap:8px;margin:0}.meta p span:first-child{white-space:nowrap}.meta p b{text-align:right}table{width:100%;border-collapse:collapse}td{padding:6px 0;border-bottom:1px solid #e8e3df;vertical-align:top}td b,td small{display:block}td small{margin-top:2px;color:#706963;font-size:10px}.amount{text-align:right;white-space:nowrap}.total{margin-top:10px;border-top:1px solid #444;padding-top:6px}.total p,.payments p{display:flex;justify-content:space-between;gap:12px;margin:6px 0}.payments{margin-top:8px;padding-top:7px;border-top:1px dashed #777}.payments span small{display:block;margin-top:2px;color:#706963;font-size:9px}.grand{font-size:15px;font-weight:700}.change{font-weight:700}.footer{margin-top:15px;padding-top:10px;border-top:1px dashed #777;text-align:center;color:#514a44;font-size:10px;line-height:1.45}.footer strong{display:block;margin-bottom:8px;color:#27231f;font-size:12px}.footer p{margin:1px 0}@media print{button{display:none}}</style></head><body><main class="sheet"><header class="head"><img src="${escapeHtml(logoUrl)}" alt="Selesa"><h1>selesa</h1><h2>SALON · SPA · WELLNESS · NAIL · EYELASH</h2><div class="receipt-number">${escapeHtml(receipt.number)}</div></header><section class="meta"><p><span>Pelanggan</span><b>: ${escapeHtml(receipt.customer)}</b></p><p><span>Transaksi</span><b>: ${escapeHtml(receipt.date)}</b></p><p><span>Karyawan</span><b>: ${escapeHtml(receipt.cashier)}</b></p></section><table><tbody>${lines}</tbody></table><section class="total"><p><span>Subtotal</span><b>${money(receipt.subtotal)}</b></p>${receipt.discount ? `<p><span>Diskon member</span><b>-${money(receipt.discount)}</b></p>` : ''}<p><span>Total</span><b>${money(receipt.total)}</b></p><p class="grand"><span>Grand Total</span><b>${money(receipt.total)}</b></p></section><section class="payments">${paymentSummary}</section><footer class="footer"><strong>TERIMA KASIH</strong><p>WhatsApp : 081128702019</p><p>Instagram : @selesa.salonspa</p></footer></main><script>window.addEventListener('load',()=>window.print());<\/script></body></html>`);
    documentWindow.document.close();
}

function printReceipt(receipt, format) {
    const compact = format === 'struk';
    const receiptMoney = (value) => money(value).replace(/^Rp\s+/, 'Rp');
    if (!compact && receipt.transactionId) {
        const preview = window.open(`/operasional/penjualan/${Number(receipt.transactionId)}/nota.pdf`, '_blank');
        if (!preview) toast('Popup nota diblokir browser. Izinkan popup lalu coba lagi.', true);
        return;
    }
    const documentWindow = window.open('', '_blank', 'width=980,height=900');
    if (!documentWindow) {
        toast('Popup cetak diblokir browser. Izinkan popup lalu coba lagi.', true);
        return;
    }

    const itemRows = receipt.items.map((item) => `<tr class="receipt-item-name"><td colspan="2">${escapeHtml(item.name)}</td></tr><tr class="receipt-item-detail"><td>${escapeHtml(String(item.quantity))} x ${receiptMoney(item.unitPrice)}</td><td>${receiptMoney(item.total)}</td></tr>`).join('');
    const cashPayments = receipt.payments.filter((payment) => payment.isCash);
    const nonCashPayments = receipt.payments.filter((payment) => !payment.isCash);
    const paymentRows = [
        ...cashPayments.map((payment) => `<tr><td class="receipt-summary-label">Tunai</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(payment.tenderedAmount)}</td></tr>`),
        ...nonCashPayments.map((payment) => `<tr><td class="receipt-summary-label">${escapeHtml(payment.name)}</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(payment.amount)}</td></tr>`),
        ...(receipt.change > 0 ? [`<tr><td class="receipt-summary-label">Kembali</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(receipt.change)}</td></tr>`] : []),
    ].join('');
    const totals = [
        `<tr><td class="receipt-summary-label">Subtotal</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(receipt.subtotal)}</td></tr>`,
        ...(receipt.discount ? [`<tr><td class="receipt-summary-label">Diskon member</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">-${receiptMoney(receipt.discount)}</td></tr>`] : []),
        ...(receipt.paymentCharge ? [`<tr><td class="receipt-summary-label">Charge pembayaran</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(receipt.paymentCharge)}</td></tr>`] : []),
        `<tr><td class="receipt-summary-label">Total</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(receipt.total)}</td></tr>`,
        `<tr class="grand-total"><td class="receipt-summary-label">Grand Total</td><td class="receipt-summary-colon">:</td><td class="receipt-summary-amount">${receiptMoney(receipt.total)}</td></tr>`,
    ].join('');
    const logoUrl = `${window.location.origin}/images/selesa-logo.png`;
    const salon = state.salon || {};
    const salonAddress = String(salon.address || 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang');
    const salonWhatsapp = String(salon.whatsapp || '081128702019');
    const pageSize = 'A4';
    const sheetClass = compact ? 'thermal' : 'nota';
    const receiptHeader = compact
        ? `<img src="${escapeHtml(logoUrl)}" alt="Logo Selesa"><p class="receipt-address">${escapeHtml(salonAddress)}</p>`
        : `<img src="${escapeHtml(logoUrl)}" alt="Logo Selesa"><p class="receipt-address">${escapeHtml(salonAddress)}</p><p class="receipt-code">${escapeHtml(receipt.number)}</p>`;

    documentWindow.document.write(`<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>${escapeHtml(receipt.number)}</title>
<style>
@page{size:${pageSize};margin:${compact ? '3mm 4mm' : '16mm'}}
*{box-sizing:border-box}
body{margin:0;color:#202020;background:#fff;font-family:Arial,Helvetica,sans-serif;font-size:${compact ? '9px' : '12px'};line-height:${compact ? '1.3' : '1.3'}}
.sheet{margin:0 auto}.sheet.thermal{width:56mm;max-width:100%}.sheet.nota{width:150mm;max-width:100%;font-size:13px}
.receipt-header{height:auto;padding-top:${compact ? '4mm' : '0'};text-align:center}.receipt-header img{display:block;width:${compact ? '36mm' : '64mm'};height:auto;margin:0 auto ${compact ? '1.5mm' : '3px'};filter:grayscale(1) contrast(1.35)}.receipt-brand{margin:0;font-size:${compact ? '16px' : '20px'};font-weight:700;line-height:1}.receipt-address{max-width:${compact ? '54mm' : '120mm'};margin:${compact ? '0 auto 1.5mm' : '0 auto 4px'};color:#514a44;font-size:${compact ? '8px' : '10px'};line-height:1.35}
.receipt-code{margin:0;font-size:${compact ? '9px' : '12px'};font-weight:700}
.dash{border-top:1px dashed #777;margin:${compact ? '0 0 2mm' : '7px 0 6px'}}.dash.thin{margin:${compact ? '2mm 0' : '6px 0'}}
.receipt-meta,.receipt-items,.receipt-totals,.receipt-payments,.receipt-contact{width:100%;border-collapse:collapse}.receipt-meta{font-size:${compact ? '9px' : '11px'}}.receipt-meta td{padding:${compact ? '.45mm 0' : '1px 0'}}.receipt-meta td:nth-child(1){width:${compact ? '17mm' : 'max-content'};white-space:nowrap}.receipt-meta td:nth-child(2){width:3mm;text-align:center}.receipt-meta td:nth-child(3){padding-left:${compact ? '.7mm' : '2px'};font-weight:400;white-space:nowrap}
.receipt-items{table-layout:fixed}.receipt-items td{padding:0}.receipt-item-name td{padding-top:${compact ? '.75mm' : '2px'};font-weight:400}.receipt-item-detail td{padding:${compact ? '.2mm 0 .75mm 1.5mm' : '1px 0 2px 2mm'}}.receipt-item-detail td:first-child{width:${compact ? '36mm' : 'auto'}}.receipt-item-detail td:last-child{width:${compact ? '20mm' : 'auto'};padding-left:${compact ? '2mm' : '4px'};text-align:right;font-weight:400;white-space:nowrap}
.receipt-totals,.receipt-payments{table-layout:fixed}.receipt-totals td,.receipt-payments td{padding:${compact ? '1.1mm 0' : '2px 0'}}.receipt-totals tr,.receipt-payments tr{border-bottom:1px dashed #777}.receipt-summary-label{width:${compact ? '33mm' : 'auto'};text-align:right}.receipt-summary-colon{width:3mm;text-align:center}.receipt-summary-amount{width:${compact ? '20mm' : 'auto'};text-align:right;white-space:nowrap}.receipt-totals .grand-total,.receipt-totals .grand-total td{font-size:${compact ? '10px' : '16px'};font-weight:700}
.reprint{text-align:center;font-size:${compact ? '9px' : '15px'};font-weight:400;margin:0}
.receipt-footer{text-align:center;margin-top:${compact ? '2.5mm' : '7px'}}.receipt-footer strong{display:block;padding-bottom:${compact ? '1.5mm' : '5px'};border-bottom:1px dashed #777;font-size:${compact ? '9px' : '15px'};margin-bottom:${compact ? '1.5mm' : '5px'}}.receipt-footer p{margin:${compact ? '.6mm 0' : '1px 0'};text-align:left;padding-left:${compact ? '1.5mm' : '15mm'}}
@media print{body{background:#fff}}
</style></head><body>
<main class="sheet ${sheetClass}">
    <header class="receipt-header">
        ${receiptHeader}
    </header>
    <div class="dash"></div>
    <table class="receipt-meta"><tbody><tr><td>Pelanggan</td><td>:</td><td>${escapeHtml(receipt.customer)}</td></tr><tr><td>Transaksi</td><td>:</td><td>${escapeHtml(receipt.date)}</td></tr><tr><td>Karyawan</td><td>:</td><td>${escapeHtml(receipt.cashier)}</td></tr></tbody></table>
    <div class="dash thin"></div>
    <p class="reprint">Cetak Ulang</p>
    <div class="dash thin"></div>
    <table class="receipt-items"><tbody>${itemRows}</tbody></table>
    <div class="dash thin"></div>
    <table class="receipt-totals"><tbody>${totals}</tbody></table>
    <table class="receipt-payments"><tbody>${paymentRows}</tbody></table>
    <footer class="receipt-footer"><strong>TERIMA KASIH</strong><p>${escapeHtml(salonAddress)}</p><p>WhatsApp&nbsp;&nbsp;: ${escapeHtml(salonWhatsapp)}</p></footer>
</main>
<script>window.addEventListener('load',()=>window.print());<\/script>
</body></html>`);
    documentWindow.document.close();
}

function openReceiptPrintChoice(receipt, options = {}) {
    const title = options.title || 'Pembayaran berhasil';
    const description = options.description || `${receipt.number} · ${money(receipt.total)}`;
    const wrapper = document.createElement('div');
    const showSuccessAnimation = options.successAnimation ?? title === 'Pembayaran berhasil';
    wrapper.className = 'modal open quick-modal';
    const printChoices = `<div class="cashier-add-choices"><button type="button" class="cashier-add-choice" data-print="struk"><i class="material-symbols-outlined">receipt_long</i><span><b>Cetak struk</b><small>Format ringkas untuk printer thermal.</small></span></button><button type="button" class="cashier-add-choice" data-print="nota"><i class="material-symbols-outlined">description</i><span><b>Cetak nota</b><small>Format rinci untuk kertas A4.</small></span></button></div><p class="print-choice-note">Dokumen dibuka di tab baru, lalu pilih printer atau simpan sebagai PDF.</p>`;
    const transactionMeta = [receipt.date, receipt.therapists?.length ? `Terapis: ${receipt.therapists.join(', ')}` : null]
        .filter(Boolean)
        .map((detail) => `<span>${escapeHtml(detail)}</span>`)
        .join('');
    const ratingTherapists = array(options.ratingTherapists);
    const starRatings = [1, 2, 3, 4, 5];
    const ratingPanel = ratingTherapists.length ? `<section class="therapist-rating-panel">
        <div class="therapist-rating-head"><span class="material-symbols-outlined" aria-hidden="true">star</span><div><b>Rating therapist</b><small>Pilih 1 sampai 5 bintang untuk setiap therapist.</small></div></div>
        <div class="therapist-rating-fields">${ratingTherapists.map((therapist) => `<fieldset class="therapist-rating-field" data-therapist-id="${Number(therapist.id)}"><legend>${escapeHtml(therapist.name)}</legend><div class="therapist-rating-stars-input">${starRatings.map((stars) => `<label class="therapist-rating-choice" title="${stars} bintang"><input type="radio" name="therapist-rating-${Number(therapist.id)}" value="${stars}" ${Number(therapist.stars) === stars ? 'checked' : ''}><span class="material-symbols-outlined" aria-label="${stars} bintang">star</span></label>`).join('')}</div><label class="therapist-rating-review">Deskripsi review <textarea name="therapist-review-${Number(therapist.id)}" maxlength="500" placeholder="Contoh: pelayanan ramah dan hasilnya memuaskan.">${escapeHtml(therapist.review || '')}</textarea><small>Opsional, maksimal 500 karakter.</small></label></fieldset>`).join('')}</div>
        <button type="button" class="save-therapist-ratings">Simpan rating therapist</button>
    </section>` : '';
    wrapper.innerHTML = showSuccessAnimation
        ? `<div class="modal-box transaction-success-modal" role="status" style="position:relative;width:min(390px,calc(100vw - 32px));min-height:410px;overflow:hidden;border:0;border-radius:22px;background:#f2f1ee;"><button type="button" class="quick-close transaction-success-close" aria-label="Tutup" style="position:absolute;z-index:1;top:13px;right:13px;width:32px;height:32px;border:0;border-radius:50%;background:transparent;cursor:pointer;"><span class="material-symbols-outlined">close</span></button><div class="transaction-success-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:295px;padding:52px 24px 30px;text-align:center;"><span class="transaction-success-emblem" aria-hidden="true" style="display:grid;place-items:center;width:90px;height:90px;margin-bottom:23px;background:#62c52f;clip-path:polygon(50% 0%,61% 9%,76% 6%,83% 20%,97% 25%,92% 40%,100% 50%,92% 60%,97% 75%,83% 80%,76% 94%,61% 91%,50% 100%,39% 91%,24% 94%,17% 80%,3% 75%,8% 60%,0 50%,8% 40%,3% 25%,17% 20%,24% 6%,39% 9%);"><svg viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" style="width:61px;height:61px;"><path d="M17 33l10 10 21-22"/></svg></span><h2 style="margin:0;color:#171513;font-size:21px;letter-spacing:.02em;">TRANSAKSI BERHASIL</h2><p style="margin:10px 0 0;color:#69635e;font-size:12px;font-weight:650;">${escapeHtml(description)}</p><div class="transaction-success-meta" style="display:grid;gap:4px;margin-top:12px;color:#827b75;font-size:9px;font-weight:600;line-height:1.35;">${transactionMeta}</div></div><div class="transaction-success-actions" style="display:grid;grid-template-columns:1fr 1fr;gap:13px;padding:0 26px 30px;"><button type="button" class="success-print-button" data-print="struk" style="min-height:55px;border:0;border-radius:17px;background:#765039;color:#fff;font:700 11px/1 inherit;letter-spacing:.02em;text-transform:uppercase;cursor:pointer;">Cetak struk</button><button type="button" class="success-print-button" data-print="nota" style="min-height:55px;border:0;border-radius:17px;background:#765039;color:#fff;font:700 11px/1 inherit;letter-spacing:.02em;text-transform:uppercase;cursor:pointer;">Cetak nota</button></div></div>`
        : `<div class="modal-box small"><div class="modal-head"><div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(description)}</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div>${printChoices}</div>`;
    document.body.appendChild(wrapper);
    if (showSuccessAnimation && ratingPanel) {
        wrapper.querySelector('.transaction-success-body')?.insertAdjacentHTML('beforeend', ratingPanel);
    }
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelectorAll('[data-print]').forEach((button) => {
        button.onclick = () => printReceipt(receipt, button.dataset.print);
    });
    wrapper.querySelector('.save-therapist-ratings')?.addEventListener('click', async (event) => {
        const saveButton = event.currentTarget;
        const ratings = ratingTherapists.map((therapist) => ({
            employee_id: Number(therapist.id),
            stars: Number(wrapper.querySelector(`input[name="therapist-rating-${Number(therapist.id)}"]:checked`)?.value || 0),
            review: String(wrapper.querySelector(`[name="therapist-review-${Number(therapist.id)}"]`)?.value || '').trim(),
        }));
        if (ratings.some((rating) => !rating.stars)) {
            toast('Pilih rating untuk setiap therapist terlebih dahulu.', true);
            return;
        }

        saveButton.disabled = true;
        try {
            const result = await api(`/operasional/penjualan/${Number(receipt.transactionId)}/penilaian-therapist`, {
                method: 'POST',
                body: JSON.stringify({ ratings }),
            });
            saveButton.textContent = 'Rating tersimpan';
            toast(result.message);
            await refresh();
        } catch (error) {
            saveButton.disabled = false;
            toast(error.message, true);
        }
    });
}

function openTherapistReviews(therapist) {
    const reviews = array(therapist.reviews);
    const stars = (value) => Array.from({ length: 5 }, (_, index) => `<i class="material-symbols-outlined${index < Number(value || 0) ? ' filled' : ''}" aria-hidden="true">star</i>`).join('');
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal therapist-review-overlay';
    wrapper.innerHTML = `<section class="modal-box small therapist-review-modal" role="dialog" aria-modal="true" aria-labelledby="therapist-review-title">
        <div class="modal-head"><div><h2 id="therapist-review-title">Review ${escapeHtml(therapist.name)}</h2><p>Ulasan rating pada bulan berjalan.</p></div><button type="button" class="quick-close" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div>
        <div class="therapist-review-list">${reviews.length ? reviews.map((review) => `<article class="therapist-review-item"><div><span class="therapist-review-stars">${stars(review.stars)}</span><time>${escapeHtml(formatTransactionDate(review.rated_at))}</time></div><p>${escapeHtml(review.review)}</p></article>`).join('') : '<p class="empty-state">Belum ada review tertulis untuk therapist ini pada bulan berjalan.</p>'}</div>
    </section>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.addEventListener('click', (event) => {
        if (event.target === wrapper) wrapper.remove();
    });
}

function commissionPercentValue(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number < 0) return '0';
    return String(Number(number.toFixed(4)));
}

function equalCommissionPercents(totalPercent, therapistCount) {
    const total = Math.max(0, Math.round(Number(totalPercent || 0) * 10000));
    const base = Math.floor(total / therapistCount);
    const remainder = total % therapistCount;

    return Array.from({ length: therapistCount }, (_, index) => commissionPercentValue((base + (index < remainder ? 1 : 0)) / 10000));
}

function treatmentCommissionProfile(treatment, therapistCount, totalPercent) {
    const profile = array(treatment.commission_profiles)
        .find((item) => Number(item.therapist_count) === Number(therapistCount));
    const values = array(profile?.commission_percents).map(commissionPercentValue);

    return values.length === therapistCount
        ? values
        : equalCommissionPercents(totalPercent, therapistCount);
}

function openTreatmentCommissionEditor(treatment) {
    const wrapper = document.createElement('div');
    const initialTotal = commissionPercentValue(treatment.default_commission_percent ?? treatment.commission_percent ?? 0);
    const countOptions = Array.from({ length: 9 }, (_, index) => index + 2);
    const drafts = new Map();
    array(treatment.commission_profiles).forEach((profile) => {
        const count = Number(profile.therapist_count);
        const values = array(profile.commission_percents).map(commissionPercentValue);
        if (count >= 2 && count <= 10 && values.length === count) drafts.set(count, values);
    });

    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small commission-profile-modal"><div class="modal-head"><div><h2>Atur komisi: ${escapeHtml(treatment.name)}</h2><p>Komisi total dibagi ke therapist sesuai jumlah yang menangani treatment.</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div><form><div class="quick-fields"><label>Komisi total untuk 1 therapist (%)<input name="default_commission_percent" type="number" min="0" max="100" step="0.0001" value="${escapeHtml(initialTotal)}" required></label><label>Profil jumlah therapist<select name="therapist_count">${countOptions.map((count) => `<option value="${count}">${count} therapist</option>`).join('')}</select></label><div class="commission-profile-head"><b>Pembagian komisi</b><button type="button" class="link" data-equal-commission>Bagi rata</button></div><div class="commission-profile-fields"></div><p class="commission-profile-note" aria-live="polite"></p></div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary">Simpan profil</button></footer></form></div>`;
    document.body.appendChild(wrapper);

    const form = wrapper.querySelector('form');
    const totalInput = form.elements.default_commission_percent;
    const countInput = form.elements.therapist_count;
    const fields = wrapper.querySelector('.commission-profile-fields');
    const note = wrapper.querySelector('.commission-profile-note');
    let activeCount = Number(countInput.value);
    const labelForPosition = (index) => index === 0 ? 'Therapist utama (%)' : `Therapist pendamping ${index} (%)`;
    const currentCount = () => activeCount;
    const currentFields = () => [...fields.querySelectorAll('input')];
    const saveDraft = (count = currentCount()) => {
        const inputs = currentFields();
        if (inputs.length) drafts.set(count, inputs.map((input) => input.value));
    };
    const renderProfile = () => {
        const count = currentCount();
        const values = drafts.get(count) || treatmentCommissionProfile(treatment, count, totalInput.value);
        fields.innerHTML = values.map((value, index) => `<label>${labelForPosition(index)}<input type="number" min="0" max="100" step="0.0001" value="${escapeHtml(value)}" required></label>`).join('');
        updateNote();
    };
    const updateNote = () => {
        const total = Math.round(Number(totalInput.value || 0) * 10000);
        const allocated = currentFields().reduce((sum, input) => sum + Math.round(Number(input.value || 0) * 10000), 0);
        const valid = Number.isFinite(total) && total >= 0 && allocated === total;
        note.classList.toggle('is-invalid', !valid);
        note.textContent = valid
            ? `Total pembagian ${commissionPercentValue(allocated / 10000)}% — sesuai komisi treatment.`
            : `Total pembagian ${commissionPercentValue(allocated / 10000)}%; harus sama dengan ${commissionPercentValue(total / 10000)}%.`;
    };

    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    countInput.addEventListener('change', () => {
        saveDraft();
        activeCount = Number(countInput.value);
        renderProfile();
    });
    totalInput.addEventListener('input', updateNote);
    fields.addEventListener('input', updateNote);
    wrapper.querySelector('[data-equal-commission]').onclick = () => {
        const values = equalCommissionPercents(totalInput.value, currentCount());
        drafts.set(currentCount(), values);
        renderProfile();
    };
    form.onsubmit = async (event) => {
        event.preventDefault();
        saveDraft();
        const totalPercent = commissionPercentValue(totalInput.value);
        const commissionPercents = drafts.get(currentCount()) || [];
        const total = Math.round(Number(totalPercent) * 10000);
        const allocated = commissionPercents.reduce((sum, value) => sum + Math.round(Number(value || 0) * 10000), 0);
        if (allocated !== total) {
            toast('Total pembagian komisi harus sama dengan komisi treatment.', true);
            return;
        }

        const button = form.querySelector('button[type="submit"], footer .primary');
        button.disabled = true;
        try {
            const result = await api(`/operasional/treatment/${Number(treatment.id)}/komisi`, {
                method: 'PATCH',
                body: JSON.stringify({
                    default_commission_percent: totalPercent,
                    commission_profiles: [{
                        therapist_count: currentCount(),
                        commission_percents: commissionPercents,
                    }],
                }),
            });
            wrapper.remove();
            toast(result.message);
            await refresh();
        } catch (error) {
            button.disabled = false;
            toast(error.message, true);
        }
    };

    renderProfile();
}

function renderTreatments() {
    const query = document.getElementById('treatment-search')?.value.trim().toLowerCase() || '';
    const treatments = array(state.treatments).filter((treatment) => !query || [
        treatment.name,
        treatment.category,
        treatment.description,
    ].some((value) => String(value || '').toLowerCase().includes(query)));
    const box = document.getElementById('treatment-grid');
    const count = document.getElementById('treatment-count');
    if (count) count.textContent = treatments.length;
    if (!box) return;

    box.innerHTML = treatments.map((treatment) => {
        const recipeCount = array(treatment.recipes).length;
        return `<article class="treatment-card">
            <span class="category">${escapeHtml(treatment.category_name || treatment.category?.name || treatment.category || '-')}</span>
            <h3>${escapeHtml(treatment.name)}</h3>
            <p><span><i class="material-symbols-outlined">schedule</i>${Number(treatment.duration_minutes)} menit</span><span><i class="material-symbols-outlined">percent</i>Komisi ${Number(treatment.default_commission_percent ?? treatment.commission_percent ?? 0)}%</span></p>
            <div class="treatment-foot"><span><small>Harga normal</small><b>${money(treatmentPrice(treatment))}</b></span><span class="treatment-actions"><button type="button" class="commission-edit" data-id="${Number(treatment.id)}">Ubah komisi</button>${recipeCount ? `<button type="button" class="recipe-info-button" data-id="${Number(treatment.id)}" title="Lihat ${recipeCount} produk dalam resep" aria-label="Lihat ${recipeCount} produk dalam resep ${escapeHtml(treatment.name)}"></button>` : ''}<button type="button" class="recipe-button" data-id="${Number(treatment.id)}">Atur resep</button></span></div>
        </article>`;
    }).join('') || '<p class="empty-state">Belum ada treatment.</p>';

    document.querySelectorAll('.recipe-button').forEach((button) => {
        const treatment = treatments.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => openRecipeChecklist(treatment);
    });
    document.querySelectorAll('.commission-edit').forEach((button) => {
        const treatment = treatments.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => {
            if (!treatment) return;
            openTreatmentCommissionEditor(treatment);
        };
    });
    document.querySelectorAll('.recipe-info-button').forEach((button) => {
        const treatment = treatments.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => openRecipeInfo(treatment);
    });
}

function openRecipeInfo(treatment) {
    const recipes = array(treatment?.recipes);
    if (!treatment || !recipes.length) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box recipe-info-modal">
        <div class="modal-head"><div><h2>Resep produk</h2><p>${escapeHtml(treatment.name)}</p></div><button type="button" class="quick-close" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div>
        <ul class="recipe-summary">${recipes.map((recipe) => `<li><span>${escapeHtml(recipe.product_name || recipe.product?.name || 'Produk')}</span><b>${escapeHtml(Number(recipe.quantity))} ${escapeHtml(recipe.unit || recipe.product?.unit || '')}</b></li>`).join('')}</ul>
        <footer><button type="button" class="secondary quick-close">Tutup</button></footer>
    </div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
}

function openRecipeChecklist(treatment) {
    if (!treatment) return;

    const products = array(state.products).filter((product) => Number(product.is_active ?? 1) === 1);
    if (!products.length) {
        toast('Tambahkan produk aktif terlebih dahulu sebelum mengatur resep.', true);
        return;
    }

    const recipes = new Map(array(treatment.recipes).map((recipe) => [Number(recipe.product_id), recipe]));
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box recipe-modal">
        <div class="modal-head"><div><h2>Atur resep produk</h2><p>${escapeHtml(treatment.name)} · centang setiap produk yang dipakai.</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div>
        <form>
            <label class="recipe-product-search">Cari produk<input type="search" id="recipe-product-search" placeholder="Nama atau kategori produk..."></label>
            <div class="recipe-checklist">${products.map((product) => {
                const recipe = recipes.get(Number(product.id));
                const checked = Boolean(recipe);
                const quantity = recipe ? String(Number(recipe.quantity)) : '';
                return `<div class="recipe-product${checked ? ' selected' : ''}">
                    <label class="recipe-product-toggle-wrap" aria-label="Pakai ${escapeHtml(product.name)}"><input class="recipe-product-toggle" type="checkbox" data-id="${Number(product.id)}" ${checked ? 'checked' : ''}></label>
                    <div class="recipe-product-info"><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.category || 'Produk')} · stok ${escapeHtml(productStock(product))} ${escapeHtml(productUnit(product))}</small></div>
                    <label class="recipe-product-quantity"><span class="sr-only">Jumlah ${escapeHtml(product.name)}</span><input class="recipe-product-quantity-input" type="number" min="0.0001" step="0.0001" inputmode="decimal" placeholder="Jumlah" value="${escapeHtml(quantity)}" ${checked ? 'required' : 'disabled'}><small>${escapeHtml(productUnit(product))}</small></label>
                </div>`;
            }).join('')}</div>
            <footer><button type="button" class="secondary quick-close">Batal</button><button class="primary" type="submit">Simpan resep</button></footer>
        </form>
    </div>`;
    document.body.appendChild(wrapper);

    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelectorAll('.recipe-product-toggle').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const row = checkbox.closest('.recipe-product');
            const quantity = row?.querySelector('.recipe-product-quantity-input');
            row?.classList.toggle('selected', checkbox.checked);
            if (!quantity) return;
            quantity.disabled = !checkbox.checked;
            quantity.required = checkbox.checked;
            if (checkbox.checked) quantity.focus();
        });
    });
    wrapper.querySelector('#recipe-product-search')?.addEventListener('input', (event) => {
        const query = event.target.value.trim().toLowerCase();
        wrapper.querySelectorAll('.recipe-product').forEach((row) => {
            row.hidden = Boolean(query) && !row.textContent.toLowerCase().includes(query);
        });
    });
    wrapper.querySelector('form').onsubmit = async (event) => {
        event.preventDefault();
        const items = [...wrapper.querySelectorAll('.recipe-product')]
            .filter((row) => row.querySelector('.recipe-product-toggle').checked)
            .map((row) => ({
                product_id: Number(row.querySelector('.recipe-product-toggle').dataset.id),
                quantity: row.querySelector('.recipe-product-quantity-input').value,
            }));
        const submit = event.currentTarget.querySelector('button[type="submit"]');

        if (!items.length && recipes.size && !window.confirm('Simpan tanpa produk? Resep treatment ini akan dikosongkan.')) return;
        submit.disabled = true;
        try {
            const result = await api(`/operasional/treatment/${treatment.id}/resep`, {
                method: 'PUT',
                body: JSON.stringify({ items }),
            });
            wrapper.remove();
            toast(result.message);
            await refresh();
        } catch (error) {
            submit.disabled = false;
            toast(error.message, true);
        }
    };
}

function renderEmployees() {
    const box = document.getElementById('employee-table');
    if (!box) return;
    const rows = employees();
    box.innerHTML = `<div class="tr th"><span>PEGAWAI</span><span>POSISI</span><span>SPESIALISASI</span><span>LAYANAN</span><span>STATUS</span><span>AKSI</span></div>${rows.map((employee) => `<div class="tr">
        <span><b>${escapeHtml(employee.name)}</b><small>${escapeHtml(employee.code || '-')}</small></span>
        <span>${escapeHtml(employee.position || '-')}</span>
        <span>${escapeHtml(employee.specialty || '-')}</span>
        <em class="pill">${Number(employee.is_service_provider ?? 0) === 1 ? 'Therapist' : 'Non-layanan'}</em>
        <em class="pill">${Number(employee.active ?? employee.is_active ?? 1) === 1 ? 'Aktif' : 'Nonaktif'}</em>
        <button class="link employee-edit" data-id="${Number(employee.id)}">Edit</button>
    </div>`).join('') || '<p class="empty-state">Belum ada pegawai.</p>'}`;

    document.querySelectorAll('.employee-edit').forEach((button) => {
        const employee = rows.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => quickForm('Edit pegawai', [
            ['name', 'Nama', 'text', null, employee.name],
            ['position', 'Posisi', 'text', null, employee.position || '-'],
            ['specialty', 'Spesialisasi', 'text', null, employee.specialty || '-'],
            ['is_service_provider', 'Dapat mengerjakan layanan', 'select', ['1|Ya', '0|Tidak'], Number(employee.is_service_provider ?? 0)],
            ['active', 'Status', 'select', ['1|Aktif', '0|Nonaktif'], Number(employee.active ?? employee.is_active ?? 1)],
        ], (data) => api(`/operasional/pegawai/${employee.id}`, {
            method: 'PATCH',
            body: JSON.stringify({
                ...data,
                position: data.position === '-' ? null : data.position,
                specialty: data.specialty === '-' ? null : data.specialty,
                is_service_provider: Number(data.is_service_provider),
                active: Number(data.active),
            }),
        }));
    });
}

function renderMembers() {
    const members = memberPageState ? array(memberPageState.data) : array(state.members);
    const dashboard = state.dashboard || {};
    const box = document.getElementById('member-list');
    const events = document.getElementById('membership-events');
    const set = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };

    set('member-count', Number(dashboard.member_count || 0));
    set('new-member-count', `${Number(dashboard.new_members_month || 0)} bulan ini`);
    set('promotion-count', Number(dashboard.active_promotion_count || 0));
    set('ending-promotion-count', `${Number(dashboard.ending_promotions_month || 0)} berakhir bulan ini`);
    set('member-transaction-percent', `${Number(dashboard.member_transaction_percent || 0)}%`);

    if (box) {
        box.innerHTML = members.map((member) => `<div class="member-row">
            <i class="avatar">${escapeHtml(String(member.name || '').split(' ').map((part) => part[0]).slice(0, 2).join(''))}</i>
            <span><b>${escapeHtml(member.name)}</b><small>${escapeHtml(member.phone || '-')}</small></span>
            <span>${Number(member.visit_count || 0)} kunjungan</span><em>Aktif</em>
            ${canManageMemberships ? `<span class="membership-actions"><button type="button" class="membership-edit" data-id="${Number(member.id)}">Edit</button><button type="button" class="membership-delete" data-id="${Number(member.id)}">Hapus</button></span>` : ''}
        </div>`).join('') || '<p class="empty-state">Belum ada member.</p>';
    }

    const pagination = document.getElementById('member-pagination');
    const meta = memberPageState?.meta;
    if (pagination) {
        pagination.innerHTML = meta && meta.last_page > 1
            ? `<small>Menampilkan ${members.length} dari ${Number(meta.total).toLocaleString('id-ID')} member</small><div><button type="button" class="member-page" data-page="${meta.current_page - 1}" ${meta.current_page <= 1 ? 'disabled' : ''}>← Sebelumnya</button><span>Halaman ${meta.current_page} / ${meta.last_page}</span><button type="button" class="member-page" data-page="${meta.current_page + 1}" ${meta.current_page >= meta.last_page ? 'disabled' : ''}>Berikutnya →</button></div>`
            : (meta ? `<small>${Number(meta.total).toLocaleString('id-ID')} member</small>` : '');
        pagination.querySelectorAll('.member-page').forEach((button) => {
            button.onclick = () => loadMembersPage(Number(button.dataset.page));
        });
    }

    if (events && canManageMemberships) {
        events.innerHTML = array(state.promotions).map((promotion) => {
            const active = Number(promotion.is_active ?? 1) === 1;
            const period = `${new Date(`${promotion.starts_at}T00:00:00`).toLocaleDateString('id-ID')}–${new Date(`${promotion.ends_at}T00:00:00`).toLocaleDateString('id-ID')}`;

            return `<article class="membership-event ${active ? '' : 'inactive'}">
                <div><small>${active ? 'AKTIF' : 'NONAKTIF'}</small><h3>${escapeHtml(promotion.name)}</h3><p>Diskon ${Number(promotion.discount_percent)}%${promotion.members_only ? ' khusus member' : ''}</p><span>${period}</span></div>
                <div class="membership-actions"><button type="button" class="membership-edit-promotion" data-id="${Number(promotion.id)}">Edit</button><button type="button" class="membership-delete-promotion" data-id="${Number(promotion.id)}">Hapus</button></div>
            </article>`;
        }).join('') || '<p class="empty-state">Belum ada event membership.</p>';
    }

    if (events && !canManageMemberships) {
        events.innerHTML = array(state.promotions).map((promotion, index) => `<div class="event ${index ? 'pale' : ''}">
            <small>AKTIF</small><h3>${escapeHtml(promotion.name)}</h3>
            <p>Diskon ${Number(promotion.discount_percent)}%${promotion.members_only ? ' khusus member' : ''}</p>
            <span>${new Date(`${promotion.starts_at}T00:00:00`).toLocaleDateString('id-ID')}–${new Date(`${promotion.ends_at}T00:00:00`).toLocaleDateString('id-ID')}</span>
        </div>`).join('') || '<p class="empty-state">Belum ada event membership aktif.</p>';
    }
}

async function loadMembersPage(page = 1) {
    if (!canViewMemberships) return;

    const search = document.getElementById('member-search')?.value.trim() || '';
    const params = new URLSearchParams({ page: String(page), per_page: '10' });
    if (search) params.set('search', search);
    memberPageState = await api(`/operasional/member?${params.toString()}`);
    renderMembers();
}

function renderStock() {
    const query = document.getElementById('stock-search')?.value.trim().toLowerCase() || '';
    const products = productPageState
        ? array(productPageState.data)
        : array(state.products).filter((product) => !query || [
            product.name,
            product.category,
            product.code,
        ].some((value) => String(value || '').toLowerCase().includes(query)));
    const movements = stockHistoryPageState
        ? array(stockHistoryPageState.data)
        : array(state.stock_movements);
    const historyMeta = stockHistoryPageState?.meta;
    const box = document.getElementById('stock-list');
    const history = document.getElementById('stock-history');
    const count = document.getElementById('product-count');
    const meta = productPageState?.meta;
    if (count) count.textContent = Number(meta?.total ?? products.length);

    if (box) {
        box.innerHTML = products.length ? `<div class="tr th"><span>PRODUK</span><span>STOK TERSEDIA</span><span>MINIMUM</span><span>HARGA JUAL</span><span>HPP / SATUAN</span><span>PERKIRAAN</span><span>STATUS</span><span>AKSI</span></div>${products.map((product) => {
            const stock = productStock(product);
            const minimum = productMinimum(product);
            const unit = productUnit(product);
            return `<div class="tr">
                <span><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.category || '-')}</small></span>
                <span><b>${stock} ${escapeHtml(unit)}</b></span><span>${minimum} ${escapeHtml(unit)}</span>
                <span class="product-price"><b>${money(product.selling_price)}</b></span>
                <span class="product-price"><b>${money(product.cost_price)}</b><small>per ${escapeHtml(unit)}</small></span>
                <span><div class="progress"><i style="width:${Math.min(100, stock / Math.max(1, minimum) * 50)}%"></i></div></span>
                <em class="pill">${stock <= minimum ? 'Menipis' : 'Aman'}</em>
                <span class="product-row-actions"><button type="button" class="product-edit" data-id="${Number(product.id)}" aria-label="Edit produk ${escapeHtml(product.name)}" title="Edit produk"><span class="material-symbols-outlined" aria-hidden="true">edit</span><span>Edit produk</span></button></span>
            </div>`;
        }).join('')}` : '<p class="empty-state">Belum ada produk.</p>';
    }

    if (history) {
        history.innerHTML = movements.length ? `<div class="tr th"><span>WAKTU</span><span>PRODUK</span><span>JENIS</span><span>JUMLAH</span><span>SUMBER</span><span>PENGGUNA</span></div>${movements.map((movement) => `<div class="tr">
            <span>${new Date(movement.created_at || movement.occurred_at).toLocaleString('id-ID')}</span>
            <span>${escapeHtml(movement.product_name || movement.product?.name || '-')}</span>
            <span>${escapeHtml(movement.type)}</span>
            <span>${Number(movement.quantity)} ${escapeHtml(movement.unit || movement.unit_code || '')}</span>
            <span>${escapeHtml(movement.source || movement.source_type || '-')}</span>
            <span>${escapeHtml(movement.user_name || movement.creator?.name || 'Sistem')}</span>
        </div>`).join('')}` : '<p class="empty-state">Tidak ada riwayat stok pada rentang tanggal ini.</p>';
    }

    const historyPagination = document.getElementById('stock-history-pagination');
    if (historyPagination) {
        historyPagination.innerHTML = historyMeta && historyMeta.last_page > 1
            ? `<small>Menampilkan ${movements.length} dari ${Number(historyMeta.total).toLocaleString('id-ID')} pergerakan</small><div><button type="button" class="stock-history-page" data-page="${historyMeta.current_page - 1}" ${historyMeta.current_page <= 1 ? 'disabled' : ''}>← Sebelumnya</button><span>Halaman ${historyMeta.current_page} / ${historyMeta.last_page}</span><button type="button" class="stock-history-page" data-page="${historyMeta.current_page + 1}" ${historyMeta.current_page >= historyMeta.last_page ? 'disabled' : ''}>Berikutnya →</button></div>`
            : (historyMeta ? `<small>${Number(historyMeta.total).toLocaleString('id-ID')} pergerakan</small>` : '');
        historyPagination.querySelectorAll('.stock-history-page').forEach((button) => {
            button.onclick = () => loadStockHistoryPage(Number(button.dataset.page));
        });
    }

    const pagination = document.getElementById('product-pagination');
    if (pagination) {
        pagination.innerHTML = meta && meta.last_page > 1
            ? `<small>Menampilkan ${products.length} dari ${Number(meta.total).toLocaleString('id-ID')} produk</small><div><button type="button" class="product-page" data-page="${meta.current_page - 1}" ${meta.current_page <= 1 ? 'disabled' : ''}>← Sebelumnya</button><span>Halaman ${meta.current_page} / ${meta.last_page}</span><button type="button" class="product-page" data-page="${meta.current_page + 1}" ${meta.current_page >= meta.last_page ? 'disabled' : ''}>Berikutnya →</button></div>`
            : (meta ? `<small>${Number(meta.total).toLocaleString('id-ID')} produk</small>` : '');
        pagination.querySelectorAll('.product-page').forEach((button) => {
            button.onclick = () => loadProductsPage(Number(button.dataset.page));
        });
    }

    document.querySelectorAll('.product-edit').forEach((button) => {
        const product = products.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!product) return;
        button.onclick = () => openProductEdit(product);
    });
}

async function loadProductsPage(page = 1) {
    if (!canViewProducts) return;

    const search = document.getElementById('stock-search')?.value.trim() || '';
    const params = new URLSearchParams({ page: String(page), per_page: '20' });
    if (search) params.set('search', search);
    productPageState = await api(`/operasional/produk?${params.toString()}`);
    renderStock();
}

async function loadStockHistoryPage(page = 1) {
    if (!canViewProducts) return;

    const from = document.getElementById('stock-history-from')?.value || '';
    const to = document.getElementById('stock-history-to')?.value || '';
    if (from && to && from > to) {
        toast('Tanggal akhir tidak boleh sebelum tanggal awal.', true);
        return;
    }

    const params = new URLSearchParams({ page: String(page), per_page: '20' });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    stockHistoryPageState = await api(`/operasional/produk/riwayat?${params.toString()}`);
    renderStock();
}

function formatStockQuantity(value) {
    return Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 });
}

function stocktakeProducts() {
    return array(state.products).filter((product) => Number(product.is_active ?? product.active ?? 1) === 1);
}

function renderStocktakeSummary() {
    const products = stocktakeProducts();
    const productById = new Map(products.map((product) => [Number(product.id), product]));
    const filledEntries = [...stocktakeDraft.entries()].filter(([id, entry]) => (
        productById.has(Number(id)) && String(entry.quantity ?? '').trim() !== ''
    ));

    const productCount = document.getElementById('stocktake-product-count');
    const filledCount = document.getElementById('stocktake-filled-count');
    const submitButton = document.getElementById('stocktake-submit');
    if (productCount) productCount.textContent = products.length;
    if (filledCount) filledCount.textContent = filledEntries.length;
    if (submitButton) submitButton.disabled = filledEntries.length === 0;
}

function renderStocktake() {
    const products = stocktakeProducts();
    const search = document.getElementById('stocktake-search')?.value.trim().toLowerCase() || '';
    const categorySelect = document.getElementById('stocktake-category');
    const selectedCategory = categorySelect?.value || '';
    const categories = [...new Set(products.map((product) => String(product.category || '').trim()).filter(Boolean))]
        .sort((first, second) => first.localeCompare(second, 'id'));

    if (categorySelect) {
        categorySelect.innerHTML = `<option value="">Semua kategori</option>${categories.map((category) => `<option value="${escapeHtml(category)}">${escapeHtml(category)}</option>`).join('')}`;
        if (categories.includes(selectedCategory)) categorySelect.value = selectedCategory;
    }

    const visibleProducts = products.filter((product) => (
        (!selectedCategory || String(product.category || '') === selectedCategory)
        && (!search || [product.name, product.code, product.category].some((value) => String(value || '').toLowerCase().includes(search)))
    ));
    const list = document.getElementById('stocktake-list');
    if (!list) return;

    list.innerHTML = visibleProducts.length ? visibleProducts.map((product) => {
        const id = Number(product.id);
        const draft = stocktakeDraft.get(id) || {};
        const unit = productUnit(product);
        return `<div class="stocktake-row" data-product-id="${id}">
            <span class="stocktake-product"><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.code || product.category || '-')}</small></span>
            <span class="stocktake-system"><b>${formatStockQuantity(productStock(product))} ${escapeHtml(unit)}</b><small>Stok saat ini</small></span>
            <label><span class="sr-only">Jumlah stok masuk ${escapeHtml(product.name)}</span><div class="stocktake-input-wrap"><input type="number" min="0.0001" step="0.0001" inputmode="decimal" data-stocktake-field="quantity" value="${escapeHtml(draft.quantity ?? '')}" placeholder="Jumlah masuk"><small>${escapeHtml(unit)}</small></div></label>
            <label><span class="sr-only">Catatan ${escapeHtml(product.name)}</span><input type="text" maxlength="1000" data-stocktake-field="notes" value="${escapeHtml(draft.notes ?? '')}" placeholder="Catatan opsional"></label>
        </div>`;
    }).join('') : '<p class="empty-state">Tidak ada produk yang cocok dengan pencarian.</p>';

    list.querySelectorAll('.stocktake-row').forEach((row) => {
        const product = visibleProducts.find((item) => Number(item.id) === Number(row.dataset.productId));
        if (!product) return;
        const id = Number(product.id);
        row.classList.toggle('filled', String(stocktakeDraft.get(id)?.quantity ?? '').trim() !== '');

        row.querySelectorAll('[data-stocktake-field]').forEach((input) => {
            input.addEventListener('input', () => {
                const draft = { ...(stocktakeDraft.get(id) || {}), [input.dataset.stocktakeField]: input.value };
                if (String(draft.quantity ?? '') === '' && String(draft.notes ?? '') === '') stocktakeDraft.delete(id);
                else stocktakeDraft.set(id, draft);
                row.classList.toggle('filled', String(draft.quantity ?? '').trim() !== '');
                renderStocktakeSummary();
            });
        });
    });

    renderStocktakeSummary();
}

async function submitStocktake(event) {
    event.preventDefault();
    const productById = new Map(stocktakeProducts().map((product) => [Number(product.id), product]));
    const entries = [...stocktakeDraft.entries()]
        .filter(([id, entry]) => productById.has(Number(id)) && String(entry.quantity ?? '').trim() !== '')
        .map(([id, entry]) => ({ id: Number(id), product: productById.get(Number(id)), ...entry }));

    if (!entries.length) {
        toast('Isi jumlah masuk minimal satu produk.', true);
        return;
    }

    const invalidEntry = entries.find((entry) => (
        !/^\d{1,14}(?:\.\d{1,4})?$/.test(String(entry.quantity).trim())
        || Number(entry.quantity) <= 0
    ));
    if (invalidEntry) {
        toast(`Jumlah masuk ${invalidEntry.product.name} harus lebih dari nol dan maksimal 4 angka desimal.`, true);
        return;
    }

    const confirmed = await confirmAction({
        title: 'Tambahkan stok masuk?',
        message: `Stok ${entries.length} produk akan ditambah sesuai jumlah yang kamu isi.`,
        confirmLabel: 'Tambah stok',
        icon: 'add_box',
    });
    if (!confirmed) return;

    const submitButton = document.getElementById('stocktake-submit');
    const originalLabel = submitButton?.innerHTML;
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">progress_activity</span> Menyimpan...';
    }

    let saved = 0;
    try {
        for (const entry of entries) {
            await api(`/operasional/produk/${entry.id}/stok`, {
                method: 'PATCH',
                body: JSON.stringify({
                    type: 'masuk',
                    quantity: String(entry.quantity).trim(),
                    source: 'Stok masuk',
                    notes: String(entry.notes || '').trim() || null,
                }),
            });
            stocktakeDraft.delete(entry.id);
            saved += 1;
        }
        await refresh();
        renderStocktake();
        toast(`Stok masuk ${saved} produk berhasil ditambahkan.`);
    } catch (error) {
        await refresh().catch(() => {});
        renderStocktake();
        toast(saved ? `${saved} produk tersimpan. Proses berhenti: ${error.message}` : error.message, true);
    } finally {
        if (submitButton) submitButton.innerHTML = originalLabel;
        renderStocktakeSummary();
    }
}

function openStockReductionForm() {
    const products = stocktakeProducts()
        .filter((product) => productStock(product) > 0)
        .sort((first, second) => String(first.name).localeCompare(String(second.name), 'id'));
    if (!products.length) {
        toast('Tidak ada produk dengan stok yang dapat dikurangi.', true);
        return;
    }

    quickForm('Pengurangan stok', [
        ['product_id', 'Produk', 'select', products.map((product) => `${product.id}|${product.name} · tersedia ${formatStockQuantity(productStock(product))} ${productUnit(product)}`)],
        ['quantity', 'Jumlah keluar', 'number', null, ''],
        ['source', 'Alasan', 'select', ['Rusak / kedaluwarsa', 'Hilang', 'Pemakaian internal', 'Sampel / tester', 'Lainnya']],
        ['notes', 'Deskripsi alasan', 'text', null, ''],
    ], async (data) => {
        if (String(data.notes || '').trim().length < 3) {
            throw new Error('Deskripsi alasan minimal 3 karakter.');
        }

        return api(`/operasional/produk/${Number(data.product_id)}/stok`, {
            method: 'PATCH',
            body: JSON.stringify({
                type: 'keluar',
                quantity: String(data.quantity || '').trim(),
                source: String(data.source || '').trim(),
                notes: String(data.notes || '').trim(),
            }),
        });
    });
}

function openProductEdit(product) {
    const form = document.getElementById('product-edit-form');
    if (!form) return;

    form.querySelector('[name="id"]').value = Number(product.id);
    form.querySelector('[name="name"]').value = product.name || '';
    form.querySelector('[name="category"]').value = product.category || '';
    form.querySelector('[name="unit_id"]').innerHTML = productUnitOptions(product.usage_unit_id);
    form.querySelector('[name="minimum_stock"]').value = Number(product.minimum_stock ?? 0);
    form.querySelector('[name="selling_price"]').value = Number(product.selling_price ?? 0);
    form.querySelector('[name="cost_price"]').value = Number(product.cost_price ?? 0);
    form.querySelector('[name="is_active"]').value = Number(product.is_active ?? 1) ? '1' : '0';
    form.querySelector('[name="description"]').value = product.description || '';
    const title = document.getElementById('product-edit-title');
    if (title) title.textContent = `Edit produk: ${product.name}`;
    modal('product-edit-modal');
    requestAnimationFrame(() => form.querySelector('[name="name"]')?.focus());
}

function financePeriodLabel(from, to) {
    if (!from && !to) return 'Bulan berjalan';
    if (!to || from === to) return `Per ${formatCashEntryDate(to || from)}`;

    return `${formatCashEntryDate(from)}–${formatCashEntryDate(to)}`;
}

function setFinanceDateValue(id, value, force = false) {
    const input = document.getElementById(id);
    if (input && value && (force || !input.value)) input.value = value;
}

function renderFinancePeriodControls() {
    const controls = [
        {
            page: 'keuangan-arus-kas',
            id: 'cash-flow-period-controls',
            title: 'Rentang arus kas',
            description: 'Menyaring ringkasan, rekening pembayaran, dan riwayat kas.',
            fields: '<label>Dari<input id="cash-flow-from" type="date" aria-label="Tanggal awal arus kas"></label><label>Sampai<input id="cash-flow-to" type="date" aria-label="Tanggal akhir arus kas"></label>',
            button: 'Terapkan',
            icon: 'filter_alt',
            scope: 'cash',
        },
        {
            page: 'keuangan-laba-rugi',
            id: 'profit-loss-period-controls',
            title: 'Rentang laba-rugi',
            description: 'Pendapatan, HPP, retur, dan biaya dihitung pada periode yang dipilih.',
            fields: '<label>Dari<input id="profit-loss-from" type="date" aria-label="Tanggal awal laba-rugi"></label><label>Sampai<input id="profit-loss-to" type="date" aria-label="Tanggal akhir laba-rugi"></label>',
            button: 'Terapkan',
            icon: 'filter_alt',
            scope: 'profit-loss',
        },
        {
            page: 'keuangan-neraca',
            id: 'balance-sheet-period-controls',
            title: 'Tanggal neraca',
            description: 'Neraca adalah posisi saldo per satu tanggal, bukan akumulasi rentang.',
            fields: '<label>Per tanggal<input id="balance-sheet-as-of" type="date" aria-label="Tanggal neraca"></label>',
            button: 'Tampilkan',
            icon: 'event',
            scope: 'balance-sheet',
        },
    ];

    controls.forEach((control) => {
        const page = document.getElementById(control.page);
        if (!page) return;
        if (!document.getElementById(control.id)) {
            page.insertAdjacentHTML('afterbegin', `<div class="finance-period-toolbar" id="${control.id}"><div><b>${control.title}</b><small>${control.description}</small></div>${control.fields}<button type="button" class="secondary" data-finance-period="${control.scope}"><span class="material-symbols-outlined" aria-hidden="true">${control.icon}</span>${control.button}</button></div>`);
        }
        const button = document.querySelector(`#${control.id} [data-finance-period]`);
        if (button) button.onclick = () => loadFinanceReport(control.scope).catch((error) => toast(error.message, true));
    });
}

async function loadFinanceReport(scope) {
    const today = localDate();
    const isCash = scope === 'cash';
    const isProfitLoss = scope === 'profit-loss';
    const from = isCash
        ? document.getElementById('cash-flow-from')?.value
        : (isProfitLoss ? document.getElementById('profit-loss-from')?.value : '');
    const to = isCash
        ? document.getElementById('cash-flow-to')?.value
        : (isProfitLoss ? document.getElementById('profit-loss-to')?.value : '');
    const asOf = scope === 'balance-sheet' ? document.getElementById('balance-sheet-as-of')?.value : '';
    if ((isCash || isProfitLoss) && (!from || !to)) throw new Error('Isi tanggal awal dan tanggal akhir terlebih dahulu.');
    if ((isCash || isProfitLoss) && from > to) throw new Error('Tanggal awal tidak boleh melewati tanggal akhir.');
    if (scope === 'balance-sheet' && !asOf) throw new Error('Pilih tanggal neraca terlebih dahulu.');
    if ([from, to, asOf].filter(Boolean).some((date) => date > today)) throw new Error('Tanggal laporan tidak boleh melewati hari ini.');

    const button = document.querySelector(`[data-finance-period="${scope}"]`);
    const originalHtml = button?.innerHTML;
    if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">progress_activity</span>Memuat';
    }
    try {
        const query = new URLSearchParams();
        if (from) query.set('from', from);
        if (to) query.set('to', to);
        if (asOf) query.set('as_of', asOf);
        const report = await api(`/operasional/keuangan/laporan?${query.toString()}`);
        if (isCash) {
            financeReports.cash = report.cash_flow;
            setFinanceDateValue('cash-entry-from', report.cash_flow.from, true);
            setFinanceDateValue('cash-entry-to', report.cash_flow.to, true);
        } else if (isProfitLoss) {
            financeReports.profitLoss = report.profit_loss;
        } else {
            financeReports.balanceSheet = report.balance_sheet;
        }
        renderFinance();
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
}

function renderFinance() {
    const dashboard = state.dashboard || {};
    const set = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };
    const defaultProfitLoss = dashboard.profit_loss_month || {};
    const defaultCashFlow = {
        from: defaultProfitLoss.from || '',
        to: defaultProfitLoss.to || localDate(),
        income: Number(dashboard.month_income || 0),
        expense: Number(dashboard.month_expense || 0),
        balance: Number(dashboard.month_balance || 0),
        entry_count: Number(dashboard.month_cash_entry_count || 0),
        expense_categories: array(dashboard.month_expense_categories),
        payment_flows: array(dashboard.payment_flows_month),
        cash_entries: array(state.cash_entries),
    };
    const cashFlow = financeReports.cash || defaultCashFlow;
    const profitLoss = financeReports.profitLoss || defaultProfitLoss;
    const balanceSheet = financeReports.balanceSheet || dashboard.balance_sheet || {};
    renderFinancePeriodControls();
    setFinanceDateValue('cash-flow-from', cashFlow.from, Boolean(financeReports.cash) || financeFiltersNeedReset);
    setFinanceDateValue('cash-flow-to', cashFlow.to, Boolean(financeReports.cash) || financeFiltersNeedReset);
    setFinanceDateValue('profit-loss-from', profitLoss.from, Boolean(financeReports.profitLoss) || financeFiltersNeedReset);
    setFinanceDateValue('profit-loss-to', profitLoss.to, Boolean(financeReports.profitLoss) || financeFiltersNeedReset);
    setFinanceDateValue('balance-sheet-as-of', balanceSheet.as_of || localDate(), Boolean(financeReports.balanceSheet) || financeFiltersNeedReset);
    const income = Number(cashFlow.income || 0);
    const expense = Number(cashFlow.expense || 0);

    set('finance-income', money(income));
    set('finance-expense', money(expense));
    set('finance-balance', money(cashFlow.balance));
    set('finance-period', financePeriodLabel(cashFlow.from, cashFlow.to));
    set('finance-cash-entry-count', Number(cashFlow.entry_count || 0));
    set('finance-cash-entry-note', financePeriodLabel(cashFlow.from, cashFlow.to));

    const flow = document.getElementById('cash-bars');
    const maximum = Math.max(income, expense, 1);
    if (flow) {
        flow.innerHTML = income || expense ? `
            <div class="cash-flow-row">
                <div class="cash-flow-head"><span>Pemasukan</span><b>${money(income)}</b></div>
                <div class="cash-flow-track"><i class="cash-flow-fill income" style="width:${income / maximum * 100}%"></i></div>
            </div>
            <div class="cash-flow-row">
                <div class="cash-flow-head"><span>Pengeluaran</span><b>${money(expense)}</b></div>
                <div class="cash-flow-track"><i class="cash-flow-fill expense" style="width:${expense / maximum * 100}%"></i></div>
            </div>` : '<p class="empty-state">Belum ada arus kas pada rentang ini.</p>';
    }

    const paymentFlows = array(cashFlow.payment_flows);
    const paymentFlowBox = document.getElementById('finance-payment-flows');
    const paymentFlowTotal = paymentFlows.reduce((sum, item) => sum + Number(item.net ?? item.total ?? 0), 0);
    set('finance-payment-flow-total', money(paymentFlowTotal));
    if (paymentFlowBox) {
        const typeMeta = {
            cash: { name: 'Tunai', icon: 'payments' },
            bank_transfer: { name: 'Transfer bank', icon: 'account_balance' },
            card: { name: 'Kartu / EDC', icon: 'credit_card' },
            qris: { name: 'QRIS', icon: 'qr_code_2' },
        };
        paymentFlowBox.innerHTML = paymentFlows.length
            ? paymentFlows.map((payment) => {
                const inflow = Number(payment.inflow || 0);
                const outflow = Number(payment.outflow || 0);
                const net = Number(payment.net ?? payment.total ?? 0);
                const key = Boolean(payment.is_cash) ? 'cash' : payment.type;
                const meta = typeMeta[key] || { name: String(key || 'Lainnya'), icon: 'payments' };
                const account = [payment.account_name, payment.account_number].filter(Boolean).join(' · ');
                const subtitle = [meta.name, account, payment.is_active === false ? 'Nonaktif · riwayat tetap ditampilkan' : null]
                    .filter(Boolean)
                    .join(' · ');

                return `<article class="finance-payment-flow-row"><div class="finance-payment-method"><i class="material-symbols-outlined" aria-hidden="true">${meta.icon}</i><span><b>${escapeHtml(payment.name)}</b><small>${escapeHtml(subtitle || 'Metode pembayaran')}</small></span></div><div class="finance-payment-amounts"><span><small>Masuk</small><b>${money(inflow)}</b></span><span><small>Refund</small><b class="expense">${money(outflow)}</b></span><strong>${money(net)}</strong></div></article>`;
            }).join('')
            : '<p class="empty-state">Belum ada metode pembayaran yang dapat ditampilkan.</p>';
    }

    const profitLossBox = document.getElementById('profit-loss-report');
    if (profitLossBox) {
        const revenueTotal = Number(profitLoss.revenue_total || 0);
        const profit = Number(profitLoss.net_profit || 0);
        const row = (label, amount, modifier = '') => `<div class="finance-statement-row ${modifier}"><span>${escapeHtml(label)}</span><b>${money(amount)}</b></div>`;
        profitLossBox.innerHTML = `${row('Penjualan treatment & produk', profitLoss.sales_revenue)}
            ${Number(profitLoss.sales_returns || 0) ? row('Retur penjualan', -Number(profitLoss.sales_returns || 0), 'negative') : ''}
            ${Number(profitLoss.payment_charge_income || 0) ? row('Charge pembayaran', profitLoss.payment_charge_income) : ''}
            ${Number(profitLoss.manual_income || 0) ? row('Pemasukan manual operasional', profitLoss.manual_income) : ''}
            ${row('Total pendapatan', revenueTotal, 'subtotal')}
            ${row('HPP treatment & produk', -Number(profitLoss.gross_hpp || 0), 'negative')}
            ${Number(profitLoss.restocked_return_hpp || 0) ? row('HPP kembali dari retur', profitLoss.restocked_return_hpp) : ''}
            ${row('Total HPP', -Number(profitLoss.hpp_total || 0), 'subtotal negative')}
            ${row('Biaya operasional manual', -Number(profitLoss.manual_expense || 0), 'negative')}
            ${row(profit >= 0 ? 'Laba bersih' : 'Rugi bersih', profit, `total ${profit < 0 ? 'negative' : 'positive'}`)}
            <p class="finance-statement-note">Periode ${escapeHtml(profitLoss.from || '-')} s.d. ${escapeHtml(profitLoss.to || '-')}.</p>`;
    }

    const balanceSheetBox = document.getElementById('balance-sheet-report');
    if (balanceSheetBox) {
        const row = (label, amount, modifier = '') => `<div class="finance-statement-row ${modifier}"><span>${escapeHtml(label)}</span><b>${money(amount)}</b></div>`;
        const accounts = array(balanceSheet.payment_accounts);
        balanceSheetBox.innerHTML = `${row('Kas fisik & mutasi manual', balanceSheet.cash)}
            ${accounts.map((account) => row(
                `${account.name}${account.account_number ? ` · ${account.account_number}` : ''}`,
                account.balance,
            )).join('')}
            ${row('Nilai persediaan (HPP)', balanceSheet.inventory)}
            ${row('Total aset', balanceSheet.assets_total, 'subtotal')}
            ${row('Kewajiban tercatat', balanceSheet.liabilities)}
            ${row('Ekuitas berjalan', balanceSheet.equity, 'total positive')}
            <p class="finance-statement-note">Per ${escapeHtml(balanceSheet.as_of || '-')}. Utang dan piutang akan bernilai nol sampai modulnya digunakan.</p>`;
    }

    const today = localDate();
    const transactions = array(state.transactions).filter((transaction) => String(transaction.created_at || transaction.transacted_at).slice(0, 10) === today);
    const box = document.getElementById('transactions');
    if (box) {
        box.innerHTML = transactions.map((transaction) => {
            const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name || payment.payment_method?.name).filter(Boolean);
            return `<div class="transaction"><i class="material-symbols-outlined">receipt_long</i><span><b>${escapeHtml(transaction.customer_name || transaction.customer?.name || 'Pelanggan')}</b><small>${escapeHtml(compactInvoiceNumber(transaction.number))} · ${escapeHtml(paymentNames.join(' + ') || transaction.payment_method || '-')}</small></span><strong>${money(transaction.total)}</strong></div>`;
        }).join('') || '<p class="empty-state">Belum ada transaksi hari ini.</p>';
    }

    const categoryBox = document.getElementById('finance-category-bars');
    if (categoryBox) {
        const categories = array(cashFlow.expense_categories);
        const categoryMaximum = Math.max(1, ...categories.map((item) => Number(item.total || 0)));
        categoryBox.innerHTML = categories.length
            ? categories.map((item) => `<div class="cash-flow-row"><div class="cash-flow-head"><span>${escapeHtml(item.category)}</span><b>${money(item.total)}</b></div><div class="cash-flow-track"><i class="cash-flow-fill expense" style="width:${Number(item.total || 0) / categoryMaximum * 100}%"></i></div></div>`).join('')
            : '<p class="empty-state">Belum ada pengeluaran kas pada rentang ini.</p>';
    }

    renderCashEntryHistory(cashFlow.cash_entries);
}

function transactionReceiptPayload(transaction) {
    const transactedAt = transaction.transacted_at || transaction.created_at;
    const transactedDate = transactedAt ? new Date(String(transactedAt).replace(' ', 'T')) : null;
    const date = transactedDate && !Number.isNaN(transactedDate.getTime())
        ? transactedDate.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
        : '-';

    return {
        transactionId: Number(transaction.id),
        number: compactInvoiceNumber(transaction.number),
        customer: transaction.customer_name || transaction.customer?.name || 'Pelanggan',
        date,
        cashier: transaction.cashier_name || 'Kasir Selesa',
        items: array(transaction.items).map((item) => ({
            name: item.name,
            quantity: Number(item.quantity || 1),
            unitPrice: Number(item.unit_price || 0),
            total: Number(item.total_amount ?? item.total ?? 0),
        })),
        payments: array(transaction.payments).map((payment) => ({
            name: payment.payment_method_name || payment.payment_method?.name || 'Pembayaran',
            isCash: Boolean(Number(payment.payment_method_is_cash ?? payment.payment_method?.is_cash ?? 0)),
            amount: Number(payment.amount || 0),
            baseAmount: Number(payment.base_amount ?? payment.amount ?? 0),
            chargeAmount: Number(payment.charge_amount || 0),
            chargePercent: Number(payment.charge_percent || 0),
            tenderedAmount: Number(payment.tendered_amount || payment.amount || 0),
            reference: payment.reference_number,
        })),
        subtotal: Number(transaction.subtotal || 0),
        discount: Number(transaction.discount_amount || 0),
        baseTotal: Number(transaction.total || 0) - Number(transaction.payment_charge_amount || 0),
        paymentCharge: Number(transaction.payment_charge_amount || 0),
        total: Number(transaction.total || 0),
        change: Number(transaction.change_amount || 0),
    };
}

function formatTransactionDate(value) {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleString('id-ID', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function setSalesView(view) {
    salesView = view === 'returns' ? 'returns' : 'sales';
    document.querySelectorAll('[data-sales-view]').forEach((button) => {
        button.classList.toggle('active', button.dataset.salesView === salesView);
        button.setAttribute('aria-selected', String(button.dataset.salesView === salesView));
    });
    const title = document.getElementById('sales-history-title');
    const subtitle = document.getElementById('sales-history-subtitle');
    const search = document.getElementById('sales-search');
    const payment = document.getElementById('sales-payment-filter');
    if (title) title.textContent = salesView === 'returns' ? 'Riwayat retur' : 'Riwayat penjualan';
    if (subtitle) subtitle.textContent = salesView === 'returns'
        ? 'Catatan pengembalian produk dan cetak struk retur.'
        : 'Invoice lunas dan cetak ulang nota.';
    if (search) {
        search.placeholder = salesView === 'returns' ? 'Cari nomor retur, invoice, atau pelanggan...' : 'Cari invoice atau pelanggan...';
        search.setAttribute('aria-label', salesView === 'returns' ? 'Cari riwayat retur' : 'Cari riwayat penjualan');
    }
    if (payment) payment.value = '';
    loadSalesPage(1).catch((error) => toast(error.message, true));
}

function renderSalesSnapshot() {
    const box = document.getElementById('sales-history');
    if (!box) return;

    const searchInput = document.getElementById('sales-search');
    const paymentFilter = document.getElementById('sales-payment-filter');
    const query = String(searchInput?.value || '').trim().toLocaleLowerCase('id-ID');
    const paymentOptions = [...new Set(array(state.transactions)
        .flatMap((transaction) => array(transaction.payments).map((payment) => payment.payment_method_name))
        .filter(Boolean))]
        .sort((left, right) => left.localeCompare(right, 'id'));

    if (paymentFilter) {
        const selected = paymentFilter.value;
        paymentFilter.innerHTML = `<option value="">Semua pembayaran</option>${paymentOptions.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('')}`;
        paymentFilter.value = paymentOptions.includes(selected) ? selected : '';
    }

    const transactions = array(state.transactions)
        .filter((transaction) => transaction.status === 'paid')
        .filter((transaction) => {
            const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name).filter(Boolean);
            const matchesPayment = !paymentFilter?.value || paymentNames.includes(paymentFilter.value);
            const haystack = [transaction.number, compactInvoiceNumber(transaction.number), transaction.customer_name, ...paymentNames]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase('id-ID');

            return matchesPayment && (!query || haystack.includes(query));
        });

    const rows = transactions.map((transaction) => {
        const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name).filter(Boolean).join(' + ') || '-';
        const itemNames = array(transaction.items).map((item) => item.name).filter(Boolean);
        const itemSummary = itemNames.length > 1 ? `${itemNames[0]} +${itemNames.length - 1}` : (itemNames[0] || '-');
        return `<div class="tr sales-row">
            <span><b>${escapeHtml(compactInvoiceNumber(transaction.number))}</b><small>${escapeHtml(formatTransactionDate(transaction.transacted_at || transaction.created_at))}</small></span>
            <span><b>${escapeHtml(transaction.customer_name || 'Pelanggan')}</b><small>${transaction.is_member ? 'Member' : 'Pelanggan umum'}</small></span>
            <span><b>${escapeHtml(itemSummary)}</b><small>${itemNames.length} item</small></span>
            <span><em class="sales-payment">${escapeHtml(paymentNames)}</em></span>
            <b class="align-right">${money(transaction.total)}</b>
            <button type="button" class="sales-reprint-button" data-id="${Number(transaction.id)}"><span class="material-symbols-outlined" aria-hidden="true">print</span> Cetak ulang</button>
        </div>`;
    }).join('');

    box.innerHTML = `<div class="tr th"><span>INVOICE & TANGGAL</span><span>PELANGGAN</span><span>RINCIAN</span><span>PEMBAYARAN</span><span class="align-right">TOTAL</span><span>AKSI</span></div>${rows || '<p class="empty-state">Belum ada transaksi lunas yang sesuai.</p>'}`;

    box.querySelectorAll('.sales-reprint-button').forEach((button) => {
        const transaction = transactions.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!transaction) return;
        button.onclick = () => openReceiptPrintChoice(transactionReceiptPayload(transaction), {
            title: 'Cetak ulang nota',
            description: `${compactInvoiceNumber(transaction.number)} · ${money(transaction.total)}`,
        });
    });
}

async function loadSalesPage(page = 1) {
    if (!canViewSales) return;

    const search = document.getElementById('sales-search')?.value.trim() || '';
    const paymentMethod = document.getElementById('sales-payment-filter')?.value || '';
    const params = new URLSearchParams({ page: String(page), per_page: '20' });
    if (search) params.set('search', search);
    if (paymentMethod) params.set('payment_method', paymentMethod);
    if (salesView === 'returns') {
        salesReturnsPageState = await api(`/operasional/retur?${params.toString()}`);
        renderSalesReturns();
        return;
    }

    salesPageState = await api(`/operasional/penjualan?${params.toString()}`);
    renderSales();
}

function renderSales() {
    if (salesView === 'returns') {
        renderSalesReturns();
        return;
    }
    const box = document.getElementById('sales-history');
    const pagination = document.getElementById('sales-pagination');
    if (!box) return;

    const paymentFilter = document.getElementById('sales-payment-filter');
    const selectedPayment = paymentFilter?.value || '';
    const paymentOptions = array(salesPageState?.payment_options).length
        ? array(salesPageState.payment_options)
        : [...new Set(array(state.transactions).flatMap((transaction) => array(transaction.payments).map((payment) => payment.payment_method_name)).filter(Boolean))];
    if (paymentFilter) {
        paymentFilter.innerHTML = `<option value="">Semua pembayaran</option>${paymentOptions.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('')}`;
        paymentFilter.value = paymentOptions.includes(selectedPayment) ? selectedPayment : '';
    }

    const transactions = Array.isArray(salesPageState?.data)
        ? salesPageState.data
        : array(state.transactions).filter((transaction) => transaction.status === 'paid');
    const rows = transactions.map((transaction) => {
        const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name).filter(Boolean).join(' + ') || '-';
        const itemNames = array(transaction.items).map((item) => item.name).filter(Boolean);
        const itemSummary = itemNames.length > 1 ? `${itemNames[0]} +${itemNames.length - 1}` : (itemNames[0] || '-');
        const refundedAmount = Number(transaction.refunded_amount || 0);
        const returnStatus = refundedAmount > 0
            ? `<em class="sales-return-status">${refundedAmount >= Number(transaction.total) ? 'Retur penuh' : 'Retur sebagian'}</em>`
            : '';
        return `<div class="tr sales-row"><span><b>${escapeHtml(compactInvoiceNumber(transaction.number))}</b><small>${escapeHtml(formatTransactionDate(transaction.transacted_at || transaction.created_at))}</small></span><span><b>${escapeHtml(transaction.customer_name || 'Pelanggan')}</b><small>${transaction.is_member ? 'Member' : 'Pelanggan umum'}</small></span><span><b>${escapeHtml(itemSummary)}</b><small>${itemNames.length} item${returnStatus}</small></span><span><em class="sales-payment">${escapeHtml(paymentNames)}</em></span><span class="sales-net-total"><b>${money(transaction.net_total ?? transaction.total)}</b>${refundedAmount > 0 ? `<small>Awal ${money(transaction.total)}</small>` : ''}</span><div class="sales-actions"><button type="button" class="sales-reprint-button" data-id="${Number(transaction.id)}"><span class="material-symbols-outlined" aria-hidden="true">print</span> Nota</button></div></div>`;
    }).join('');
    box.innerHTML = `<div class="tr th"><span>INVOICE & TANGGAL</span><span>PELANGGAN</span><span>RINCIAN</span><span>PEMBAYARAN</span><span class="align-right">TOTAL</span><span>AKSI</span></div>${rows || '<p class="empty-state">Belum ada transaksi lunas yang sesuai.</p>'}`;

    box.querySelectorAll('.sales-reprint-button').forEach((button) => {
        const transaction = transactions.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!transaction) return;
        button.onclick = () => openReceiptPrintChoice(transactionReceiptPayload(transaction), {
            title: 'Cetak ulang nota',
            description: `${compactInvoiceNumber(transaction.number)} \u00b7 ${money(transaction.total)}`,
        });
    });
    const meta = salesPageState?.meta;
    if (pagination) {
        pagination.innerHTML = meta && meta.last_page > 1
            ? `<small>Menampilkan ${transactions.length} dari ${Number(meta.total).toLocaleString('id-ID')} transaksi</small><div><button type="button" class="sales-page" data-page="${meta.current_page - 1}" ${meta.current_page <= 1 ? 'disabled' : ''}>← Sebelumnya</button><span>Halaman ${meta.current_page} / ${meta.last_page}</span><button type="button" class="sales-page" data-page="${meta.current_page + 1}" ${meta.current_page >= meta.last_page ? 'disabled' : ''}>Berikutnya →</button></div>`
            : (meta ? `<small>${Number(meta.total).toLocaleString('id-ID')} transaksi</small>` : '');
        pagination.querySelectorAll('.sales-page').forEach((button) => {
            button.onclick = () => loadSalesPage(Number(button.dataset.page));
        });
    }
}

function renderSalesReturns() {
    const box = document.getElementById('sales-history');
    const pagination = document.getElementById('sales-pagination');
    if (!box) return;

    const paymentFilter = document.getElementById('sales-payment-filter');
    const selectedPayment = paymentFilter?.value || '';
    const paymentOptions = array(salesReturnsPageState?.payment_options);
    if (paymentFilter) {
        paymentFilter.innerHTML = `<option value="">Semua metode refund</option>${paymentOptions.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('')}`;
        paymentFilter.value = paymentOptions.includes(selectedPayment) ? selectedPayment : '';
    }

    const returns = array(salesReturnsPageState?.data);
    const rows = returns.map((salesReturn) => {
        const items = array(salesReturn.items);
        const itemNames = items.map((item) => item.product_name).filter(Boolean);
        const itemSummary = itemNames.length > 1 ? `${itemNames[0]} +${itemNames.length - 1}` : (itemNames[0] || '-');
        const itemQuantity = items.reduce((total, item) => total + Number(item.quantity || 0), 0);
        return `<div class="tr sales-row sales-return-row"><span><b>${escapeHtml(salesReturn.number)}</b><small>${escapeHtml(formatTransactionDate(salesReturn.returned_at))}</small></span><span><b>${escapeHtml(salesReturn.customer_name || 'Pelanggan')}</b><small>Invoice ${escapeHtml(compactInvoiceNumber(salesReturn.transaction_number))}</small></span><span><b>${escapeHtml(itemSummary)}</b><small>${itemQuantity.toLocaleString('id-ID')} item · ${escapeHtml(salesReturn.reason)}</small></span><span><em class="sales-payment">${escapeHtml(salesReturn.payment_method_name || '-')}</em></span><b class="align-right sales-return-amount">−${money(salesReturn.total_amount)}</b><div class="sales-actions"><button type="button" class="sales-return-receipt" data-return-id="${Number(salesReturn.id)}"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span> Struk retur</button></div></div>`;
    }).join('');
    box.innerHTML = `<div class="tr th"><span>RETUR & TANGGAL</span><span>PELANGGAN & INVOICE</span><span>PRODUK & ALASAN</span><span>METODE REFUND</span><span class="align-right">NOMINAL</span><span>AKSI</span></div>${rows || '<p class="empty-state">Belum ada retur yang sesuai.</p>'}`;
    box.querySelectorAll('.sales-return-receipt').forEach((button) => {
        button.onclick = () => window.open(`/operasional/retur/${Number(button.dataset.returnId)}/struk.pdf`, '_blank', 'noopener');
    });

    const meta = salesReturnsPageState?.meta;
    if (pagination) {
        pagination.innerHTML = meta && meta.last_page > 1
            ? `<small>Menampilkan ${returns.length} dari ${Number(meta.total).toLocaleString('id-ID')} retur</small><div><button type="button" class="sales-page" data-page="${meta.current_page - 1}" ${meta.current_page <= 1 ? 'disabled' : ''}>← Sebelumnya</button><span>Halaman ${meta.current_page} / ${meta.last_page}</span><button type="button" class="sales-page" data-page="${meta.current_page + 1}" ${meta.current_page >= meta.last_page ? 'disabled' : ''}>Berikutnya →</button></div>`
            : (meta ? `<small>${Number(meta.total).toLocaleString('id-ID')} retur</small>` : '');
        pagination.querySelectorAll('.sales-page').forEach((button) => {
            button.onclick = () => loadSalesPage(Number(button.dataset.page));
        });
    }
}

function openSalesReturn(transaction) {
    const products = array(transaction.items).filter((item) => item.item_type === 'product' && Number(item.refundable_quantity || 0) > 0);
    if (!products.length) {
        toast('Tidak ada produk yang masih dapat diretur.', true);
        return;
    }

    const methods = array(salesPageState?.refund_payment_options);
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal sales-return-overlay';
    wrapper.innerHTML = `<div class="modal-box sales-return-modal">
        <div class="modal-head sales-return-head"><div><span class="sales-return-kicker">Retur produk</span><h2>${escapeHtml(compactInvoiceNumber(transaction.number))}</h2><p>${escapeHtml(transaction.customer_name || 'Pelanggan')} · Pilih produk dan jumlah yang dikembalikan.</p></div><button type="button" class="quick-close" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div>
        <form class="sales-return-form">
            <div class="sales-return-products">${products.map((item) => `<article class="sales-return-product" data-item-id="${Number(item.id)}" data-price="${Number(item.unit_price)}">
                <div><strong>${escapeHtml(item.name)}</strong><small>Terjual ${Number(item.quantity).toLocaleString('id-ID')} · Sudah diretur ${Number(item.returned_quantity || 0).toLocaleString('id-ID')}</small></div>
                <label>Qty retur<input class="sales-return-quantity" type="number" min="0" max="${Number(item.refundable_quantity)}" step="0.0001" value="0"></label>
                <label class="sales-return-restock"><input type="checkbox" class="sales-return-restock-input" checked><span>Kembali ke stok</span></label>
                <b class="sales-return-line-total">${money(0)}</b>
            </article>`).join('')}</div>
            <div class="sales-return-fields">
                <label>Metode pengembalian dana<select name="payment_method_id" required><option value="">Pilih metode</option>${methods.map((method) => `<option value="${Number(method.id)}" data-reference="${method.requires_reference ? '1' : '0'}">${escapeHtml(method.name)}</option>`).join('')}</select></label>
                <label class="sales-return-reference" hidden>Nomor referensi<input name="reference_number" maxlength="100" placeholder="Nomor referensi refund"></label>
                <label class="sales-return-reason">Alasan retur<textarea name="reason" rows="3" minlength="5" maxlength="2000" required placeholder="Contoh: Produk tidak sesuai atau kemasan rusak"></textarea></label>
            </div>
            <div class="sales-return-summary"><span><small>Total pengembalian dana</small><strong class="sales-return-total">${money(0)}</strong></span><p>Nominal dihitung otomatis dari harga pada invoice.</p></div>
            <footer><button type="button" class="secondary quick-close">Batal</button><button type="submit" class="primary sales-return-submit"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span> Proses retur</button></footer>
        </form>
    </div>`;
    document.body.appendChild(wrapper);

    const form = wrapper.querySelector('form');
    const totalElement = wrapper.querySelector('.sales-return-total');
    const methodSelect = form.elements.payment_method_id;
    const referenceLabel = wrapper.querySelector('.sales-return-reference');
    const referenceInput = form.elements.reference_number;
    const calculate = () => {
        let total = 0;
        wrapper.querySelectorAll('.sales-return-product').forEach((row) => {
            const quantity = Math.max(0, Number(row.querySelector('.sales-return-quantity').value || 0));
            const amount = Math.round(quantity * Number(row.dataset.price || 0));
            row.querySelector('.sales-return-line-total').textContent = money(amount);
            total += amount;
        });
        totalElement.textContent = money(total);
        return total;
    };
    wrapper.querySelectorAll('.sales-return-quantity').forEach((input) => input.addEventListener('input', calculate));
    methodSelect.onchange = () => {
        const requiresReference = methodSelect.selectedOptions[0]?.dataset.reference === '1';
        referenceLabel.hidden = !requiresReference;
        referenceInput.required = requiresReference;
        if (!requiresReference) referenceInput.value = '';
    };
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.onclick = (event) => { if (event.target === wrapper) wrapper.remove(); };
    form.onsubmit = async (event) => {
        event.preventDefault();
        const items = [...wrapper.querySelectorAll('.sales-return-product')].map((row) => ({
            transaction_item_id: Number(row.dataset.itemId),
            quantity: Number(row.querySelector('.sales-return-quantity').value || 0).toFixed(4),
            restock: row.querySelector('.sales-return-restock-input').checked,
        })).filter((item) => Number(item.quantity) > 0);
        if (!items.length || calculate() <= 0) {
            toast('Isi jumlah pada minimal satu produk.', true);
            return;
        }

        const button = wrapper.querySelector('.sales-return-submit');
        button.disabled = true;
        try {
            const result = await api(`/operasional/penjualan/${Number(transaction.id)}/retur`, {
                method: 'POST',
                body: JSON.stringify({
                    items,
                    payment_method_id: Number(methodSelect.value),
                    reference_number: referenceInput.value.trim() || null,
                    reason: form.elements.reason.value.trim(),
                    idempotency_key: `return:${Number(transaction.id)}:${globalThis.crypto?.randomUUID?.() || Date.now()}`,
                }),
            });
            await refresh();
            wrapper.innerHTML = `<div class="modal-box sales-return-success"><span class="material-symbols-outlined" aria-hidden="true">check</span><small>RETUR BERHASIL</small><h2>${escapeHtml(result.number)}</h2><p>Pengembalian dana sebesar <b>${money(result.total_amount)}</b> sudah dicatat dan seluruh laporan telah diperbarui.</p><button type="button" class="primary sales-return-print"><span class="material-symbols-outlined" aria-hidden="true">print</span> Cetak struk retur</button><button type="button" class="secondary sales-return-done">Selesai</button></div>`;
            wrapper.querySelector('.sales-return-print').onclick = () => window.open(`/operasional/retur/${Number(result.id)}/struk.pdf`, '_blank', 'noopener');
            wrapper.querySelector('.sales-return-done').onclick = () => wrapper.remove();
            toast('Retur dan refund berhasil diproses.');
        } catch (error) {
            toast(error.message, true);
            button.disabled = false;
        }
    };
    wrapper.querySelector('.sales-return-quantity')?.focus();
}

function formatCashEntryDate(value) {
    if (!value) return '-';

    return new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function renderCashEntryHistory(cashEntries = state.cash_entries) {
    const box = document.getElementById('cash-entry-history');
    if (!box) return;

    const typeFilter = document.getElementById('cash-entry-type-filter');
    const search = document.getElementById('cash-entry-search');
    const from = document.getElementById('cash-entry-from')?.value || '';
    const to = document.getElementById('cash-entry-to')?.value || '';
    const type = typeFilter?.value || '';
    const keyword = String(search?.value || '').trim().toLocaleLowerCase('id-ID');
    const entries = array(cashEntries).filter((entry) => {
        const matchesType = !type || entry.type === type;
        const entryDate = String(entry.entry_date || '').slice(0, 10);
        const matchesDate = (!from || entryDate >= from) && (!to || entryDate <= to);
        const haystack = [entry.category, entry.description, entry.created_by_name, entry.transaction_number]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('id-ID');

        return matchesType && matchesDate && (!keyword || haystack.includes(keyword));
    });

    const rows = entries.map((entry) => {
        const isIncome = entry.type === 'income';
        const automated = Boolean(entry.automated || entry.transaction_payment_id);
        return `<div class="tr finance-history-row">
            <span>${escapeHtml(formatCashEntryDate(entry.entry_date))}</span>
            <span><em class="finance-type ${isIncome ? 'income' : 'expense'}">${isIncome ? 'Pemasukan' : 'Pengeluaran'}</em></span>
            <span><b>${escapeHtml(entry.category)}</b><small>${escapeHtml(entry.description)}</small></span>
            <span><small class="finance-source ${automated ? 'automatic' : 'manual'}">${automated ? `Otomatis${entry.transaction_number ? ` · ${escapeHtml(compactInvoiceNumber(entry.transaction_number))}` : ''}` : 'Manual'}</small></span>
            <span>${escapeHtml(entry.created_by_name || '-')}</span>
            <b class="align-right finance-amount ${isIncome ? 'income' : 'expense'}">${isIncome ? '+' : '−'}${money(entry.amount)}</b>
        </div>`;
    }).join('');
    box.innerHTML = `<div class="tr th"><span>TANGGAL</span><span>ARUS</span><span>KATEGORI & CATATAN</span><span>SUMBER</span><span>DICATAT OLEH</span><span class="align-right">NOMINAL</span></div>${rows || '<p class="empty-state">Tidak ada riwayat arus kas yang sesuai.</p>'}`;
}

function openCashEntryForm() {
    if (!canManageFinance) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small finance-entry-modal">
        <div class="modal-head"><div><h2>Input kas</h2><p>Catat modal, pemasukan, pembelian, atau biaya operasional salon.</p></div><button type="button" class="quick-close" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div>
        <form>
            <div class="quick-fields">
                <label>Jenis arus<select name="type"><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></label>
                <label>Tanggal<input name="entry_date" type="date" value="${localDate()}" max="${localDate()}" required></label>
                <label>Kelompok laporan<select name="report_group"><option value="operating">Operasional (laba-rugi)</option><option value="capital">Modal pemilik (neraca)</option><option value="inventory">Pembelian persediaan (neraca)</option><option value="owner_draw">Prive pemilik (neraca)</option></select></label>
                <label>Kategori<input name="category" list="cash-entry-categories" maxlength="100" placeholder="Pilih atau tulis kategori" required><datalist id="cash-entry-categories"><option value="Modal usaha"></option><option value="Pembelian bahan & produk"></option><option value="Biaya operasional"></option><option value="Gaji & komisi"></option><option value="Sewa & utilitas"></option></datalist></label>
                <label>Nominal (Rp)<input name="amount" type="number" min="1" step="1" inputmode="numeric" placeholder="0" required></label>
                <label class="finance-entry-description">Catatan<textarea name="description" rows="3" maxlength="2000" placeholder="Contoh: Beli tisu dan air minum untuk operasional" required></textarea></label>
            </div>
            <footer><button type="button" class="secondary quick-close">Batal</button><button class="primary" type="submit">Simpan catatan</button></footer>
        </form>
    </div>`;
    document.body.appendChild(wrapper);

    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelector('form').onsubmit = async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const result = await api('/operasional/keuangan/arus-kas', {
                method: 'POST',
                body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))),
            });
            wrapper.remove();
            toast(result.message);
            await refresh();
        } catch (error) {
            button.disabled = false;
            toast(error.message, true);
        }
    };
}

function renderPayroll() {
    const box = document.getElementById('payroll-table');
    if (!box) return;
    const payrolls = array(state.payrolls);
    box.innerHTML = `<div class="tr th"><span>KARYAWAN</span><span>GAJI POKOK</span><span>BONUS</span><span>KETERLAMBATAN</span><span>KOMISI</span><span>GAJI AKHIR</span><span>AKSI</span></div>${payrolls.map((payroll) => `<div class="tr">
        <span><b>${escapeHtml(payroll.employee_name || payroll.employee?.name || '-')}</b><small>${escapeHtml(payroll.position || payroll.employee?.position || '-')}</small></span>
        <span>${money(payroll.base_salary)}</span><span>${money(payroll.bonus)}</span>
        <span>${Number(payroll.late_duration_minutes || 0)} menit<small>-${money(payroll.late_deduction)}</small></span>
        <span>${money(payroll.commission)}</span>
        <b>${money(payroll.net_salary ?? (Number(payroll.base_salary) + Number(payroll.bonus) + Number(payroll.overtime || 0) + Number(payroll.commission) - Number(payroll.late_deduction) - Number(payroll.other_deduction || 0)))}</b>
        <button class="link payroll-edit" data-id="${Number(payroll.id)}">Edit</button>
    </div>`).join('')}`;

    document.querySelectorAll('.payroll-edit').forEach((button) => {
        const payroll = payrolls.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => quickForm('Edit gaji', [
            ['base_salary', 'Gaji pokok', 'number', null, payroll.base_salary],
            ['bonus', 'Bonus', 'number', null, payroll.bonus],
            ['late_deduction', 'Potongan keterlambatan', 'number', null, payroll.late_deduction],
            ['late_duration_minutes', 'Durasi terlambat (menit)', 'number', null, payroll.late_duration_minutes || 0],
        ], (data) => api(`/operasional/penggajian/${payroll.id}`, {
            method: 'PATCH',
            body: JSON.stringify(data),
        }));
    });
}

function openPayrollForm() {
    const initialPeriod = localDate().slice(0, 7);
    const activeEmployees = employees().filter((employee) => Number(employee.active ?? employee.is_active ?? 1) === 1);
    const availableEmployees = (period) => {
        const recordedEmployeeIds = new Set(array(state.payrolls)
            .filter((payroll) => String(payroll.period) === period)
            .map((payroll) => Number(payroll.employee_id)));

        return activeEmployees.filter((employee) => !recordedEmployeeIds.has(Number(employee.id)));
    };
    const initialEmployees = availableEmployees(initialPeriod);
    if (!initialEmployees.length) {
        toast('Semua karyawan aktif sudah memiliki data gaji untuk periode ini.', true);
        return;
    }

    const employeeLabel = (employee) => `${employee.name}${employee.position ? ` · ${employee.position}` : ''}`;
    const wrapper = quickForm('Input penggajian', [
        ['employee_id', 'Karyawan', 'select', initialEmployees.map((employee) => `${employee.id}|${employeeLabel(employee)}`)],
        ['period', 'Periode gaji', 'month', null, initialPeriod],
        ['base_salary', 'Gaji pokok', 'number', null, 0],
        ['bonus', 'Bonus', 'number', null, 0],
        ['overtime', 'Upah lembur', 'number', null, 0],
        ['late_duration_minutes', 'Keterlambatan (menit)', 'number', null, 0],
        ['late_deduction', 'Potongan keterlambatan', 'number', null, 0],
        ['other_deduction', 'Potongan lain', 'number', null, 0],
    ], (data) => api('/operasional/penggajian', {
        method: 'POST',
        body: JSON.stringify(data),
    }));

    const periodInput = wrapper.querySelector('[name="period"]');
    const employeeSelect = wrapper.querySelector('[name="employee_id"]');
    const submitButton = wrapper.querySelector('button[type="submit"], footer .primary');
    const refreshEmployeeOptions = () => {
        const options = availableEmployees(periodInput.value);
        employeeSelect.innerHTML = options.length
            ? options.map((employee) => `<option value="${Number(employee.id)}">${escapeHtml(employeeLabel(employee))}</option>`).join('')
            : '<option value="" selected>Semua karyawan sudah tercatat</option>';
        employeeSelect.disabled = options.length === 0;
        submitButton.disabled = options.length === 0;
    };
    periodInput.addEventListener('change', refreshEmployeeOptions);
}

function activityCategory(action) {
    const value = String(action || '').toLowerCase();
    if (value.startsWith('reservation')) return 'Reservasi';
    if (value.startsWith('stock')) return 'Stok opname';
    if (value.startsWith('product')) return 'Produk';
    if (value.startsWith('treatment')) return 'Treatment';
    if (value.startsWith('transaction')) return value.includes('refund') ? 'Retur' : 'Penjualan';
    if (value.startsWith('finance')) return 'Arus kas';
    if (value.startsWith('payroll')) return 'Penggajian';
    if (value.startsWith('membership')) return 'Membership';
    if (value.startsWith('promotion')) return 'Promo membership';
    if (value.startsWith('employee')) return 'Pegawai';
    if (value.startsWith('therapist.rated')) return 'Penilaian therapist';
    if (value.startsWith('therapist')) return 'Kehadiran terapis';
    if (value.startsWith('settings')) return 'Pengaturan';
    return 'Aktivitas sistem';
}

function activityIcon(action) {
    const category = activityCategory(action);
    return {
        Reservasi: 'calendar_month',
        'Stok opname': 'inventory_2',
        Produk: 'inventory',
        Treatment: 'spa',
        Penjualan: 'payments',
        Retur: 'assignment_return',
        'Arus kas': 'account_balance_wallet',
        Penggajian: 'payments',
        Membership: 'workspace_premium',
        'Promo membership': 'campaign',
        Pegawai: 'badge',
        'Kehadiran terapis': 'event_available',
        'Penilaian therapist': 'sentiment_satisfied',
        Pengaturan: 'settings',
    }[category] || 'work_history';
}

function renderActivity() {
    const box = document.getElementById('activity-list');
    if (!box) return;
    const activities = array(state.activities);
    const dateFilter = document.getElementById('activity-filter-date');
    const userFilter = document.getElementById('activity-filter-user');
    const actionFilter = document.getElementById('activity-filter-action');
    const searchFilter = document.getElementById('activity-search');
    const users = [...new Set(activities.map((activity) => activity.user_name || 'Sistem'))].sort((a, b) => a.localeCompare(b, 'id'));
    const actions = [...new Set(activities.map((activity) => activityCategory(activity.action)))].sort((a, b) => a.localeCompare(b, 'id'));

    if (userFilter) {
        const selected = userFilter.value;
        userFilter.innerHTML = `<option value="">Semua pengguna</option>${users.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('')}`;
        userFilter.value = users.includes(selected) ? selected : '';
    }
    if (actionFilter) {
        const selected = actionFilter.value;
        actionFilter.innerHTML = `<option value="">Semua jenis aktivitas</option>${actions.map((action) => `<option value="${escapeHtml(action)}">${escapeHtml(action)}</option>`).join('')}`;
        actionFilter.value = actions.includes(selected) ? selected : '';
    }

    const searchTerm = String(searchFilter?.value || '').trim().toLocaleLowerCase('id-ID');
    const rows = activities.filter((activity) => {
        const searchable = [
            activityCategory(activity.action),
            activity.description,
            activity.reservation_customer_name,
            activity.reservation_queue_number,
            activity.user_name || 'Sistem',
            activity.action,
        ].join(' ').toLocaleLowerCase('id-ID');

        return (!dateFilter?.value || String(activity.created_at || '').slice(0, 10) === dateFilter.value)
            && (!userFilter?.value || (activity.user_name || 'Sistem') === userFilter.value)
            && (!actionFilter?.value || activityCategory(activity.action) === actionFilter.value)
            && (!searchTerm || searchable.includes(searchTerm));
    });

    box.innerHTML = rows.map((activity) => `<div class="activity">
        <time>${new Date(activity.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</time>
        <i class="material-symbols-outlined">${activityIcon(activity.action)}</i>
        <span><b>${escapeHtml(activity.description || activityCategory(activity.action))}</b><small>${activity.reservation_customer_name ? `Customer: ${escapeHtml(activity.reservation_customer_name)}${activity.reservation_queue_number ? ` (${escapeHtml(activity.reservation_queue_number)})` : ''} · ` : ''}Oleh ${escapeHtml(activity.user_name || activity.user?.name || 'Sistem')}</small><em>${escapeHtml(activityCategory(activity.action))}</em></span>
    </div>`).join('') || '<p class="empty-state">Tidak ada aktivitas yang sesuai filter.</p>';
}

function compactMoney(value) {
    const number = Number(value || 0);
    if (number >= 1000000000) return `Rp${(number / 1000000000).toLocaleString('id-ID', { maximumFractionDigits: 1 })}M`;
    if (number >= 1000000) return `Rp${(number / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 })}jt`;
    if (number >= 1000) return `Rp${(number / 1000).toLocaleString('id-ID', { maximumFractionDigits: 0 })}rb`;
    return money(number);
}

function renderRevenueTrend(dashboard) {
    const chart = document.getElementById('revenue-chart');
    const description = document.getElementById('revenue-chart-description');
    const buttons = document.querySelectorAll('[data-revenue-period]');
    if (!chart) return;

    const periods = {
        week: {
            items: array(dashboard.revenue_last_7_days),
            description: 'Transaksi dibayar pada minggu berjalan',
            totalLabel: 'Total minggu ini',
            averageLabel: 'Rata-rata / hari',
            ariaLabel: 'Grafik pendapatan minggu berjalan dari Senin sampai Minggu',
            emptyLabel: 'Belum ada data pendapatan pada minggu berjalan.',
        },
        month: {
            items: array(dashboard.revenue_current_month),
            description: 'Transaksi dibayar pada bulan berjalan',
            totalLabel: 'Total bulan ini',
            averageLabel: 'Rata-rata / hari',
            ariaLabel: 'Grafik pendapatan bulan berjalan',
            emptyLabel: 'Belum ada data pendapatan pada bulan berjalan.',
        },
        year: {
            items: array(dashboard.revenue_current_year),
            description: 'Transaksi dibayar pada tahun berjalan',
            totalLabel: 'Total tahun ini',
            averageLabel: 'Rata-rata / bulan',
            ariaLabel: 'Grafik pendapatan tahun berjalan',
            emptyLabel: 'Belum ada data pendapatan pada tahun berjalan.',
        },
    };
    const selectedPeriod = periods[revenueChartPeriod] ? revenueChartPeriod : 'week';
    const config = periods[selectedPeriod];
    const revenue = config.items;

    if (description) description.textContent = config.description;
    buttons.forEach((button) => {
        const isActive = button.dataset.revenuePeriod === selectedPeriod;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', String(isActive));
        button.onclick = () => {
            revenueChartPeriod = button.dataset.revenuePeriod;
            renderRevenueTrend(state.dashboard || {});
        };
    });

    if (!revenue.length) {
        chart.innerHTML = `<p class="empty-state">${config.emptyLabel}</p>`;
        return;
    }

    const totals = revenue.map((item) => Number(item.total || 0));
    const maximum = Math.max(0, ...totals);
    const minimum = Math.min(0, ...totals);
    const scale = maximum - minimum;
    const total = totals.reduce((sum, amount) => sum + amount, 0);
    const average = Math.round(total / revenue.length);
    const points = revenue.map((item, index) => {
        const itemDate = selectedPeriod === 'year'
            ? new Date(`${item.date}-01T12:00:00`)
            : new Date(`${item.date}T12:00:00`);
        const dateLabel = Number.isNaN(itemDate.getTime())
            ? item.date
            : new Intl.DateTimeFormat('id-ID', selectedPeriod === 'year'
                ? { month: 'long', year: 'numeric' }
                : { day: '2-digit', month: 'short', year: 'numeric' }).format(itemDate);

        return {
            px: revenue.length === 1 ? 50 : 3 + (index * (94 / (revenue.length - 1))),
            py: scale ? 8 + ((maximum - Number(item.total || 0)) / scale * 84) : 92,
            total: Number(item.total || 0),
            label: item.label,
            date: dateLabel,
        };
    });
    const zeroY = scale ? 8 + ((maximum / scale) * 84) : 92;
    const line = points.map((point) => `${point.px},${point.py}`).join(' ');
    const area = `${points[0].px},${zeroY} ${line} ${points.at(-1).px},${zeroY}`;
    const middleAxis = maximum - ((maximum - minimum) / 2);
    const labels = points
        .map((point) => `<span title="${escapeHtml(point.date)}">${escapeHtml(point.label)}</span>`)
        .join('');

    chart.innerHTML = `<div class="revenue-chart-summary"><span><small>${config.totalLabel}</small><strong>${money(total)}</strong></span><span><small>${config.averageLabel}</small><strong>${money(average)}</strong></span></div><div class="revenue-line-canvas"><div class="revenue-line-plot"><span class="axis a1">${compactMoney(maximum)}</span><span class="axis a2">${compactMoney(middleAxis)}</span><span class="axis a3">${compactMoney(minimum)}</span><div class="chart-grid"></div><svg class="revenue-line-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="${config.ariaLabel}"><defs><linearGradient id="revenue-area-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#a87559" stop-opacity=".20"></stop><stop offset="100%" stop-color="#a87559" stop-opacity=".015"></stop></linearGradient></defs><polygon points="${area}"></polygon><polyline points="${line}"></polyline></svg>${points.map((point) => `<button type="button" class="revenue-line-point" style="--x:${point.px}%;--y:${point.py}%" aria-label="Pendapatan ${escapeHtml(point.date)}: ${money(point.total)}" data-date="${escapeHtml(point.date)}" data-total="${money(point.total)}"></button>`).join('')}<div class="revenue-line-tooltip" role="status" aria-live="polite"><small></small><strong></strong></div></div><div class="chart-labels">${labels}</div></div>`;

    const tooltip = chart.querySelector('.revenue-line-tooltip');
    chart.querySelectorAll('.revenue-line-point').forEach((point) => {
        const showTooltip = () => {
            tooltip.querySelector('small').textContent = point.dataset.date;
            tooltip.querySelector('strong').textContent = point.dataset.total;
            tooltip.style.setProperty('--tooltip-x', point.style.getPropertyValue('--x'));
            tooltip.style.setProperty('--tooltip-y', point.style.getPropertyValue('--y'));
            tooltip.classList.add('is-visible');
        };
        point.addEventListener('mouseenter', showTooltip);
        point.addEventListener('focus', showTooltip);
        point.addEventListener('mouseleave', () => tooltip.classList.remove('is-visible'));
        point.addEventListener('blur', () => tooltip.classList.remove('is-visible'));
    });
}

function renderDashboard() {
    const dashboard = state.dashboard || {};
    const set = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };

    set('metric-reservations', Number(dashboard.reservations_today || 0));
    set('metric-serving', `${Number(dashboard.serving_today || 0)} sedang dilayani`);
    set('metric-arrived', Number(dashboard.arrived_today || 0));
    set('metric-arrival-rate', `${Number(dashboard.arrival_percent || 0)}% dari reservasi`);
    set('metric-revenue', money(dashboard.revenue_today));

    const current = Number(dashboard.revenue_today || 0);
    const previous = Number(dashboard.revenue_yesterday || 0);
    let trend = 'Belum ada transaksi hari ini';
    if (current > 0 && previous === 0) trend = 'Belum ada pendapatan kemarin';
    else if (previous > 0) {
        const change = Math.round(Math.abs(current - previous) / previous * 100);
        trend = current === previous ? 'Sama dengan kemarin' : `${current > previous ? 'Naik' : 'Turun'} ${change}% dari kemarin`;
    }
    set('metric-revenue-trend', trend);

    const paymentRevenue = array(dashboard.revenue_by_payment_method_today);
    const paymentRevenueList = document.getElementById('payment-revenue-list');
    const totalPaymentRevenue = paymentRevenue.find((payment) => payment.key === 'total');
    const paymentMethodsRevenue = paymentRevenue.filter((payment) => payment.key !== 'total');
    const paymentTypeMeta = {
        cash: { name: 'Tunai', icon: 'payments' },
        bank_transfer: { name: 'Transfer bank', icon: 'account_balance' },
        card: { name: 'Kartu / EDC', icon: 'credit_card' },
        qris: { name: 'QRIS', icon: 'qr_code_2' },
    };
    set('payment-revenue-total', money(totalPaymentRevenue?.total || 0));
    set('payment-revenue-note', `${paymentMethodsRevenue.length} metode pembayaran`);
    if (paymentRevenueList) {
        const totalInflow = paymentMethodsRevenue.reduce((sum, payment) => sum + Number(payment.inflow || 0), 0);
        paymentRevenueList.innerHTML = paymentMethodsRevenue.length ? paymentMethodsRevenue.map((payment) => {
            const inflow = Number(payment.inflow || 0);
            const outflow = Number(payment.outflow || 0);
            const net = Number(payment.net ?? payment.total ?? 0);
            const key = Boolean(payment.is_cash) ? 'cash' : payment.type;
            const meta = paymentTypeMeta[key] || { name: String(key || 'Lainnya'), icon: 'payments' };
            const account = [payment.account_name, payment.account_number].filter(Boolean).join(' · ');
            const percent = totalInflow > 0 ? Math.round((inflow / totalInflow) * 100) : 0;
            payment.icon = meta.icon;
            payment.activeCount = true;
            payment.name = [payment.name, meta.name, account, outflow > 0 ? `Refund ${money(outflow)}` : null]
                .filter(Boolean)
                .join(' · ');
            return `<article class="payment-revenue-item">
                <div class="payment-method-label"><i class="material-symbols-outlined" aria-hidden="true">${payment.icon}</i><span><b>${escapeHtml(payment.name)}</b><small>${payment.activeCount ? `${percent}% dari pendapatan hari ini` : 'Kategori nonaktif · riwayat tetap tercatat'}</small></span></div>
                <strong>${money(net)}</strong>
                <div class="payment-revenue-track" aria-label="Dana masuk ${money(inflow)}"><i style="width:${percent}%"></i></div>
            </article>`;
        }).join('') : '<p class="empty-state">Belum ada metode pembayaran aktif.</p>';
    }

    const low = Number(dashboard.low_stock_count || 0);
    set('metric-low-stock', `${low} produk`);
    set('metric-stock-note', low ? 'Perlu ditambah' : 'Stok aman');
    const badge = document.querySelector('.bell sup');
    if (badge) {
        badge.textContent = low;
        badge.hidden = low === 0;
    }

    renderRevenueTrend(dashboard);

    const treatments = array(dashboard.treatment_daily_current_month);
    const performance = document.getElementById('treatment-performance');
    const treatmentPeriod = document.getElementById('treatment-volume-period');
    if (performance) {
        const maximum = Math.max(0, ...treatments.map((item) => Number(item.total || 0)));
        const total = treatments.reduce((sum, item) => sum + Number(item.total || 0), 0);
        const firstDate = treatments[0]?.date ? new Date(`${treatments[0].date}T12:00:00`) : null;
        if (treatmentPeriod && firstDate) {
            treatmentPeriod.textContent = new Intl.DateTimeFormat('id-ID', { month: 'short', year: 'numeric' }).format(firstDate).toUpperCase();
        }
        const width = Math.max(390, treatments.length * 31);
        performance.innerHTML = treatments.length ? `<div class="treatment-bar-summary"><span><small>Total bulan ini</small><strong>${total.toLocaleString('id-ID')} treatment</strong></span><span><small>Tertinggi per hari</small><strong>${maximum.toLocaleString('id-ID')} treatment</strong></span></div><div class="treatment-bar-scroll"><div class="treatment-bar-inner" style="--treatment-chart-width:${width}px;--treatment-chart-count:${treatments.length}"><div class="treatment-bar-yaxis"><span>${maximum}</span><span>${Math.round(maximum / 2)}</span><span>0</span></div><div class="treatment-bar-plot">${treatments.map((item, index) => {
            const count = Number(item.total || 0);
            const height = maximum ? Math.max(4, Math.round((count / maximum) * 100)) : 4;
            const date = new Date(`${item.date}T12:00:00`);
            const dateLabel = Number.isNaN(date.getTime())
                ? item.date
                : new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
            const tooltip = `${dateLabel} \u00b7 ${count.toLocaleString('id-ID')} treatment`;
            const positionClass = `${index === 0 ? ' is-first' : ''}${index === treatments.length - 1 ? ' is-current is-last' : ''}`;
            return `<button type="button" class="treatment-day-bar${positionClass}" style="--bar-height:${height}%" data-tooltip="${escapeHtml(tooltip)}" aria-label="${escapeHtml(tooltip)}"><span>${count || ''}</span><i></i></button>`;
        }).join('')}</div></div></div>` : '<p class="empty-state">Belum ada treatment yang dibayar pada bulan ini.</p>';

        let tooltip = document.getElementById('treatment-chart-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'treatment-chart-tooltip';
            tooltip.className = 'treatment-chart-tooltip';
            tooltip.hidden = true;
            document.body.appendChild(tooltip);
        }

        const hideTooltip = () => { tooltip.hidden = true; };
        const positionTooltip = (x, y) => {
            tooltip.hidden = false;
            const halfWidth = tooltip.offsetWidth / 2;
            const left = Math.min(Math.max(x, halfWidth + 10), window.innerWidth - halfWidth - 10);
            tooltip.style.left = `${left}px`;
            tooltip.style.top = `${Math.max(10, y)}px`;
        };
        performance.querySelectorAll('.treatment-day-bar').forEach((bar) => {
            const showTooltip = (event) => {
                tooltip.textContent = bar.dataset.tooltip || '';
                positionTooltip(event.clientX, event.clientY - 14);
            };
            bar.addEventListener('pointerenter', showTooltip);
            bar.addEventListener('pointermove', showTooltip);
            bar.addEventListener('pointerleave', hideTooltip);
            bar.addEventListener('focus', () => {
                tooltip.textContent = bar.dataset.tooltip || '';
                const rect = bar.getBoundingClientRect();
                positionTooltip(rect.left + (rect.width / 2), rect.top - 8);
            });
            bar.addEventListener('blur', hideTooltip);
        });
    }

    const popularTreatments = document.getElementById('popular-treatments');
    if (popularTreatments) {
        const items = array(dashboard.treatment_most_frequent_current_month);
        const maximum = Math.max(1, ...items.map((item) => Number(item.total || 0)));
        popularTreatments.innerHTML = items.length
            ? items.map((item, index) => {
                const total = Number(item.total || 0);
                const quantity = total.toLocaleString('id-ID', { maximumFractionDigits: 2 });
                const width = Math.max(3, Math.round((total / maximum) * 100));

                return `<article class="performance-item"><span class="performance-rank">${index + 1}</span><div><b>${escapeHtml(item.name || 'Treatment')}</b><small>${quantity} treatment dibayar</small><i><em style="width:${width}%"></em></i></div><strong>${quantity}×</strong></article>`;
            }).join('')
            : '<p class="empty-state">Belum ada treatment yang dibayar bulan ini.</p>';
    }

    const treatmentStockAlerts = document.getElementById('treatment-stock-alerts');
    if (treatmentStockAlerts) {
        const alerts = array(dashboard.treatment_stock_alerts);
        treatmentStockAlerts.innerHTML = alerts.length
            ? alerts.map((alert) => `<div class="treatment-stock-alert"><i class="material-symbols-outlined" aria-hidden="true">warning</i><span><b>${escapeHtml(alert.treatment_name)}</b><small>${escapeHtml(alert.product_name)} · tersisa ${Number(alert.current_stock || 0).toLocaleString('id-ID')} ${escapeHtml(alert.unit || '')}</small></span><em>Menipis</em></div>`).join('')
            : '<p class="empty-state">Semua bahan resep aman. Menu treatment siap dijual.</p>';
    }

    const therapistAvailability = document.getElementById('therapist-availability');
    if (therapistAvailability) {
        const attendance = dashboard.therapist_attendance_today || {};
        const present = array(attendance.present);
        const off = array(attendance.off);
        const names = (therapists) => therapists.length
            ? therapists.map((therapist) => `<span class="therapist-name">${escapeHtml(therapist.name)}</span>`).join('')
            : '<small>Tidak ada</small>';

        therapistAvailability.innerHTML = `<div class="therapist-attendance-summary">
            <span><i class="material-symbols-outlined present" aria-hidden="true">check_circle</i><b>${present.length}</b><small>hadir</small></span>
            <span><i class="material-symbols-outlined off" aria-hidden="true">event_busy</i><b>${off.length}</b><small>libur</small></span>
        </div><div class="therapist-attendance-status present"><div><i class="material-symbols-outlined" aria-hidden="true">check</i><b>Hadir</b></div><p>${names(present)}</p></div><div class="therapist-attendance-status off"><div><i class="material-symbols-outlined" aria-hidden="true">hotel</i><b>Libur</b></div><p>${names(off)}</p></div>`;
    }

    const therapistRatingList = document.getElementById('therapist-rating-list');
    if (therapistRatingList) {
        const ratings = array(dashboard.therapist_rating_summary_current_month);
        const quality = (average) => {
            if (Number(average) >= 4.5) return ['Sangat baik', 'professional'];
            if (Number(average) >= 3.5) return ['Bagus', 'good'];
            return ['Perlu evaluasi', 'poor'];
        };
        const stars = (average) => Array.from({ length: 5 }, (_, index) => `<i class="material-symbols-outlined${index < Math.round(Number(average || 0)) ? ' filled' : ''}" aria-hidden="true">star</i>`).join('');
        therapistRatingList.innerHTML = ratings.length
            ? ratings.map((therapist, index) => {
                const [label, tone] = quality(therapist.average);
                const score = Math.max(0, Math.min(100, (Number(therapist.average || 0) / 5) * 100));
                const reviewCount = Number(therapist.review_count || 0);
                return `<article class="therapist-rating-summary"><span class="therapist-rating-rank">${index + 1}</span><div class="therapist-rating-person"><b>${escapeHtml(therapist.name)}</b><small>${escapeHtml(therapist.position || 'Therapist')} · ${Number(therapist.total || 0)} rating</small><i><em style="width:${score}%"></em></i></div><div class="therapist-rating-score"><em class="${tone}">${label}</em><span class="therapist-rating-stars">${stars(therapist.average)}</span><strong>${Number(therapist.average || 0).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 2 })}/5</strong><small>${Number(therapist.stars_5 || 0)} rating bintang 5</small><button type="button" class="therapist-rating-reviews" data-therapist-review-index="${index}" ${reviewCount ? '' : 'disabled'}><span class="material-symbols-outlined" aria-hidden="true">reviews</span>${reviewCount ? `Lihat ${reviewCount} review` : 'Belum ada review'}</button></div></article>`;
            }).join('')
            : '<p class="empty-state">Belum ada rating therapist pada bulan ini.</p>';
        therapistRatingList.querySelectorAll('[data-therapist-review-index]').forEach((button) => {
            button.addEventListener('click', () => openTherapistReviews(ratings[Number(button.dataset.therapistReviewIndex)]));
        });
    }
}

function renderAll() {
    renderDashboard();
    renderReservations();
    renderEmployees();
    renderCashier();
    renderTreatments();
    renderMembers();
    renderStock();
    renderStocktake();
    renderSales();
    renderFinance();
    renderPayroll();
    renderActivity();
}

function modal(id) {
    document.getElementById(id)?.classList.add('open');
}

function closeModal(element) {
    element?.closest('.modal')?.classList.remove('open');
}

function employeeOptions(selected = '') {
    return serviceProviders().map((employee) => `<option value="${Number(employee.id)}" ${Number(employee.id) === Number(selected) ? 'selected' : ''}>${escapeHtml(employee.name)}${employee.specialty ? ` · ${escapeHtml(employee.specialty)}` : ''}</option>`).join('');
}

function treatmentOptions(selected = '') {
    return array(state.treatments).map((treatment) => `<option value="${Number(treatment.id)}" ${Number(treatment.id) === Number(selected) ? 'selected' : ''}>${escapeHtml(treatment.name)} · ${money(treatmentPrice(treatment))}</option>`).join('');
}

function reservationTreatmentMatches(query = '') {
    const normalized = String(query).trim().toLocaleLowerCase('id-ID');

    return array(state.treatments)
        .filter((treatment) => Number(treatment.is_active ?? 1) === 1)
        .filter((treatment) => !normalized || [
            treatment.name,
            treatment.code,
            treatment.category_name,
            treatment.category,
            treatment.description,
        ].some((value) => String(value || '').toLocaleLowerCase('id-ID').includes(normalized)))
        .slice(0, 12);
}

function closeReservationTreatmentPickers(except = null) {
    document.querySelectorAll('.treatment-picker-label').forEach((label) => {
        if (label === except) return;
        label.classList.remove('open', 'opens-up');
        const results = label.querySelector('.treatment-search-results');
        if (results) results.hidden = true;
    });
}

function renderReservationTreatmentPicker(card, { showResults = false } = {}) {
    const label = card.querySelector('.treatment-picker-label');
    const select = card.querySelector('.item-treatment');
    const input = card.querySelector('.item-treatment-search');
    const results = card.querySelector('.treatment-search-results');
    if (!label || !select || !input || !results) return;

    const matches = reservationTreatmentMatches(input.value);
    const selectedId = Number(select.value || 0);
    results.innerHTML = matches.map((treatment) => `<button type="button" class="treatment-search-option${Number(treatment.id) === selectedId ? ' selected' : ''}" data-treatment-id="${Number(treatment.id)}" role="option" aria-selected="${Number(treatment.id) === selectedId}"><span><b>${escapeHtml(treatment.name)}</b><small>${escapeHtml(treatment.category_name || treatment.category?.name || treatment.category || 'Treatment')} &middot; ${money(treatmentPrice(treatment))}</small></span><i class="material-symbols-outlined" aria-hidden="true">add_circle</i></button>`).join('') || '<p class="treatment-search-empty">Treatment tidak ditemukan.</p>';
    results.hidden = !showResults;
    label.classList.toggle('open', showResults);
    label.classList.toggle('opens-up', showResults && results.getBoundingClientRect().bottom > window.innerHeight - 16);

    results.querySelectorAll('[data-treatment-id]').forEach((option) => {
        option.addEventListener('click', () => {
            const treatment = array(state.treatments).find((item) => Number(item.id) === Number(option.dataset.treatmentId));
            if (!treatment) return;
            select.value = String(treatment.id);
            input.value = treatment.name;
            select.dispatchEvent(new Event('change'));
            closeReservationTreatmentPickers();
        });
    });
}

function addStaffRow(container, role = 'primary') {
    const row = document.createElement('div');
    row.className = 'staff-row';
    row.innerHTML = `<label class="therapist-picker-label">Therapist<select class="item-employee" aria-hidden="true" tabindex="-1"><option value="">Pilih therapist</option>${employeeOptions()}</select><button type="button" class="therapist-picker" aria-haspopup="listbox" aria-expanded="false"><span>Pilih therapist</span><i class="material-symbols-outlined" aria-hidden="true">expand_more</i></button><div class="therapist-picker-menu" role="listbox" hidden></div></label>
        <label>Peran<select class="item-staff-role"><option value="primary" ${role === 'primary' ? 'selected' : ''}>Utama</option><option value="assistant" ${role === 'assistant' ? 'selected' : ''}>Pendamping</option></select></label>
        <button type="button" class="icon-button remove-staff" aria-label="Hapus therapist"><span class="material-symbols-outlined">close</span></button>`;
    container.appendChild(row);
    row.querySelector('.item-employee').addEventListener('change', () => {
        renderReservationTherapistPicker(row, container.closest('.reservation-item-card')?._therapistAvailability || []);
    });
    row.querySelector('.therapist-picker').addEventListener('click', () => toggleReservationTherapistPicker(row));
    row.querySelector('.therapist-picker-menu').addEventListener('click', (event) => {
        const option = event.target.closest('[data-employee-id]');
        if (!option || option.disabled) return;
        row.querySelector('.item-employee').value = option.dataset.employeeId;
        row.querySelector('.item-employee').dispatchEvent(new Event('change'));
        closeReservationTherapistPickers();
    });
    renderReservationTherapistPicker(row, []);
    row.querySelector('.remove-staff').onclick = () => {
        if (container.children.length <= 1) {
            toast('Setiap treatment minimal memiliki satu therapist.', true);
            return;
        }
        row.remove();
    };
}

function reservationReadyTime(value) {
    if (!value) return null;
    const ready = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));

    return Number.isNaN(ready.getTime()) ? null : ready;
}

function reservationDurationLabel(minutes) {
    const total = Math.max(0, Math.round(minutes));
    const hours = Math.floor(total / 60);
    const remainder = total % 60;

    if (hours && remainder) return `${hours} jam ${remainder} menit`;
    if (hours) return `${hours} jam`;
    return `${remainder} menit`;
}

function reservationTherapistConflict(row, employee) {
    const conflicts = array(employee?.conflicts);
    if (!conflicts.length) return null;

    const conflict = conflicts
        .map((item) => {
            const end = reservationReadyTime(item.end_at);
            const ready = reservationReadyTime(item.ready_at) || (end ? new Date(end.getTime() + (45 * 60 * 1000)) : null);

            return { ...item, ready };
        })
        .filter((item) => item.ready)
        .sort((left, right) => right.ready.getTime() - left.ready.getTime())[0];
    if (!conflict) return null;

    const selectedDate = document.getElementById('reservation-date')?.value;
    const selectedTime = row.closest('.reservation-item-card')?.querySelector('.item-time')?.value;
    const selectedStart = selectedDate && selectedTime ? new Date(`${selectedDate}T${selectedTime}:00`) : null;
    const remaining = selectedStart && !Number.isNaN(selectedStart.getTime())
        ? Math.max(0, Math.round((conflict.ready.getTime() - selectedStart.getTime()) / 60000))
        : null;
    const readyAt = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(conflict.ready);
    const remainingLabel = remaining === null ? '' : ` · ${reservationDurationLabel(remaining)} lagi`;

    return { readyAt, remainingLabel };
}

function closeReservationTherapistPickers(except = null) {
    document.querySelectorAll('.therapist-picker-label').forEach((label) => {
        if (label === except) return;
        label.classList.remove('open', 'opens-up');
        label.querySelector('.therapist-picker')?.setAttribute('aria-expanded', 'false');
        const menu = label.querySelector('.therapist-picker-menu');
        if (menu) menu.hidden = true;
    });
}

function toggleReservationTherapistPicker(row) {
    const label = row.querySelector('.therapist-picker-label');
    const menu = row.querySelector('.therapist-picker-menu');
    const picker = row.querySelector('.therapist-picker');
    const opening = menu.hidden;
    closeReservationTherapistPickers(label);
    if (!opening) {
        closeReservationTherapistPickers();
        return;
    }

    menu.hidden = false;
    label.classList.add('open');
    picker.setAttribute('aria-expanded', 'true');
    label.classList.toggle('opens-up', menu.getBoundingClientRect().bottom > window.innerHeight - 16);
}

function renderReservationTherapistPicker(row, availability) {
    const select = row.querySelector('.item-employee');
    const picker = row.querySelector('.therapist-picker');
    const menu = row.querySelector('.therapist-picker-menu');
    const employees = array(availability).length ? array(availability) : serviceProviders().map((employee) => ({ ...employee, available: true, conflicts: [] }));
    let selectedId = Number(select.value || 0);
    const selectedEmployee = employees.find((employee) => Number(employee.id) === selectedId);
    const selectedConflict = reservationTherapistConflict(row, selectedEmployee);
    if (selectedEmployee && ((selectedConflict && !selectedEmployee.available) || selectedEmployee.attendance_status === 'off')) {
        select.value = '';
        selectedId = 0;
    }

    const selected = employees.find((employee) => Number(employee.id) === selectedId);
    picker.querySelector('span').textContent = selected
        ? `${selected.name}${selected.specialty ? ` · ${selected.specialty}` : ''}`
        : 'Pilih therapist';
    menu.innerHTML = employees.map((employee) => {
        const conflict = reservationTherapistConflict(row, employee);
        const busy = !employee.available && Boolean(conflict);
        const off = employee.attendance_status === 'off';
        const unavailable = busy || off;
        const detail = busy
            ? `<small><i class="material-symbols-outlined" aria-hidden="true">schedule</i> Siap ${escapeHtml(conflict.readyAt)}${conflict.remainingLabel}</small>`
            : off
                ? '<small>Libur hari ini</small>'
                : `<small>${escapeHtml(employee.specialty || employee.position || 'Therapist')}</small>`;

        return `<button type="button" role="option" class="therapist-picker-option${unavailable ? ' unavailable' : ''}${Number(employee.id) === selectedId ? ' selected' : ''}" data-employee-id="${Number(employee.id)}" aria-selected="${Number(employee.id) === selectedId}" ${unavailable ? 'disabled' : ''}><span><b>${escapeHtml(employee.name)}</b>${detail}</span>${busy ? '<i class="material-symbols-outlined" aria-hidden="true">schedule</i>' : ''}</button>`;
    }).join('') || '<p class="therapist-picker-empty">Tidak ada therapist tersedia.</p>';
}

async function refreshReservationTherapistAvailability(card) {
    const date = document.getElementById('reservation-date')?.value;
    const treatmentId = Number(card.querySelector('.item-treatment')?.value || 0);
    const startTime = card.querySelector('.item-time')?.value;
    const statusRows = () => card.querySelectorAll('.staff-row');

    if (!date || !treatmentId || !startTime) {
        card._therapistAvailability = [];
        statusRows().forEach((row) => renderReservationTherapistPicker(row, []));
        return;
    }

    const requestId = (card._therapistAvailabilityRequestId || 0) + 1;
    card._therapistAvailabilityRequestId = requestId;
    try {
        const data = await api(`/operasional/reservasi/terapis-tersedia?date=${encodeURIComponent(date)}&start_time=${encodeURIComponent(startTime)}&treatment_id=${treatmentId}`);
        if (card._therapistAvailabilityRequestId !== requestId) return;
        card._therapistAvailability = array(data.employees);
        statusRows().forEach((row) => renderReservationTherapistPicker(row, card._therapistAvailability));
    } catch {
        if (card._therapistAvailabilityRequestId !== requestId) return;
        card._therapistAvailability = [];
        statusRows().forEach((row) => renderReservationTherapistPicker(row, []));
    }
}

function reservationTimeOptions(selected = '09:00') {
    selected = String(selected).slice(0, 5);
    return Array.from({ length: 157 }, (_, index) => {
        const totalMinutes = (9 * 60) + (index * 5);
        const hour = String(Math.floor(totalMinutes / 60)).padStart(2, '0');
        const minute = String(totalMinutes % 60).padStart(2, '0');
        const value = `${hour}:${minute}`;
        return `<option value="${value}" ${value === selected ? 'selected' : ''}>${value}</option>`;
    }).join('');
}

function addReservationItem(values = {}) {
    const container = document.getElementById('reservation-items');
    if (!container) return;
    const card = document.createElement('article');
    card.className = 'reservation-item-card';
    const itemNumber = container.children.length + 1;
    card.innerHTML = `<div class="reservation-item-title"><strong>Treatment ${itemNumber}</strong><button type="button" class="icon-button remove-reservation-item" aria-label="Hapus treatment"><span class="material-symbols-outlined">delete</span></button></div>
        <div class="reservation-item-grid">
            <label class="treatment-picker-label">Treatment<select class="item-treatment" required aria-hidden="true" tabindex="-1"><option value="">Pilih treatment</option>${treatmentOptions(values.treatment_id)}</select><div class="treatment-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input class="item-treatment-search" type="search" autocomplete="off" placeholder="Cari treatment..." aria-label="Cari treatment"></div><div class="treatment-search-results" role="listbox" hidden></div></label>
            <label class="time-field">Jam mulai (24 jam)<select class="item-time" required>${reservationTimeOptions(values.start_time || '09:00')}</select><small>Slot setiap 5 menit</small></label>
            ${capabilities.override_price ? `<label>Harga aktual<input class="item-price" type="number" min="0" step="1" placeholder="Harga normal" value="${escapeHtml(values.actual_price || '')}"></label>` : '<span class="reservation-price-note"><small>Harga</small><b>Mengikuti harga normal</b></span>'}
        </div>
        <label class="item-notes">Catatan treatment<textarea class="item-note" placeholder="Opsional">${escapeHtml(values.notes || '')}</textarea></label>
        <div class="staff-block"><div class="staff-block-head"><span>Pembagian therapist</span><button type="button" class="link add-staff"><span class="material-symbols-outlined">add</span> Tambah therapist</button></div><div class="staff-rows"></div></div>`;
    container.appendChild(card);

    const staffContainer = card.querySelector('.staff-rows');
    const selectedTreatment = array(state.treatments).find((item) => Number(item.id) === Number(values.treatment_id));
    const treatmentSearch = card.querySelector('.item-treatment-search');
    if (selectedTreatment && treatmentSearch) treatmentSearch.value = selectedTreatment.name;
    treatmentSearch?.addEventListener('focus', () => {
        closeReservationTreatmentPickers(card.querySelector('.treatment-picker-label'));
        renderReservationTreatmentPicker(card, { showResults: true });
    });
    treatmentSearch?.addEventListener('input', () => renderReservationTreatmentPicker(card, { showResults: true }));
    treatmentSearch?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeReservationTreatmentPickers();
    });
    renderReservationTreatmentPicker(card);
    addStaffRow(staffContainer, 'primary');
    card.querySelector('.add-staff').onclick = () => addStaffRow(staffContainer, 'assistant');
    card.querySelector('.remove-reservation-item').onclick = () => {
        if (container.children.length <= 1) {
            toast('Reservasi minimal memiliki satu treatment.', true);
            return;
        }
        card.remove();
        renumberReservationItems();
    };
    card.querySelector('.item-treatment').onchange = (event) => {
        const treatment = array(state.treatments).find((item) => Number(item.id) === Number(event.target.value));
        if (treatment && treatmentSearch) treatmentSearch.value = treatment.name;
        const priceInput = card.querySelector('.item-price');
        if (treatment && priceInput && !priceInput.value) priceInput.placeholder = String(treatmentPrice(treatment));
        refreshReservationTherapistAvailability(card);
    };
    card.querySelector('.item-time').addEventListener('change', () => refreshReservationTherapistAvailability(card));
}

function renumberReservationItems() {
    document.querySelectorAll('#reservation-items .reservation-item-card').forEach((card, index) => {
        card.querySelector('.reservation-item-title strong').textContent = `Treatment ${index + 1}`;
    });
}

function resetReservationForm() {
    const form = document.getElementById('reservation-form');
    form?.reset();
    syncReservationCustomerType();
    const date = document.getElementById('reservation-date');
    if (date) date.value = localDate();
    const items = document.getElementById('reservation-items');
    if (items) items.innerHTML = '';
    addReservationItem();
    hideConflictPanel();
    pendingReservationPayload = null;
}

function setReservationLaunchContext(context = 'reservation') {
    reservationLaunchContext = context === 'cashier' ? 'cashier' : 'reservation';
    const fromCashier = reservationLaunchContext === 'cashier';
    const title = document.getElementById('reservation-modal-title');
    const subtitle = document.getElementById('reservation-modal-subtitle');
    const submitLabel = document.getElementById('reservation-submit-label');

    if (title) title.textContent = fromCashier ? 'Transaksi baru' : 'Reservasi baru';
    if (subtitle) subtitle.textContent = fromCashier
        ? 'Catat pelanggan walk-in dan treatment untuk langsung diproses di kasir'
        : 'Satu kunjungan dapat memuat beberapa treatment dan therapist';
    if (submitLabel) submitLabel.textContent = fromCashier ? 'Buka di kasir' : 'Simpan reservasi';
}

function openReservationForm(values = {}) {
    hideReservationCalendarTooltip();
    resetReservationForm();
    setReservationLaunchContext(values.context);

    const date = document.getElementById('reservation-date');
    if (date && values.date) date.value = values.date;

    const source = document.querySelector('#reservation-form [name="source"]');
    if (source && values.source && [...source.options].some((option) => option.value === values.source)) {
        source.value = values.source;
    }

    const startTime = document.querySelector('#reservation-items .item-time');
    if (startTime && values.startTime) startTime.value = values.startTime;

    const therapist = document.querySelector('#reservation-items .item-employee');
    if (therapist && values.employeeId && [...therapist.options].some((option) => Number(option.value) === Number(values.employeeId))) {
        therapist.value = String(values.employeeId);
    }

    document.querySelectorAll('#reservation-items .reservation-item-card').forEach((card) => refreshReservationTherapistAvailability(card));

    modal('reservation-modal');
    requestAnimationFrame(() => document.querySelector('#reservation-form [name="name"]')?.focus());
}

function syncReservationCustomerType() {
    const form = document.getElementById('reservation-form');
    const type = form?.querySelector('[name="customer_type"]:checked')?.value || 'guest';
    const picker = document.getElementById('reservation-member-picker');
    const memberId = document.getElementById('reservation-member-id');
    const select = memberId;
    const search = document.getElementById('reservation-member-search');
    const clear = document.getElementById('reservation-member-clear');
    const triggerLabel = document.getElementById('reservation-member-trigger-label');
    const preview = document.getElementById('reservation-member-preview');
    const name = form?.querySelector('[name="name"]');
    const phone = form?.querySelector('[name="phone"]');
    const isMember = type === 'member';

    if (picker) picker.hidden = !isMember;
    if (!isMember) closeReservationMemberSearch();
    [name, phone].filter(Boolean).forEach((field) => {
        field.disabled = isMember;
        field.required = !isMember;
    });
    if (select?.tagName === 'SELECT') {
        const selectedMemberId = select.value;
        select.required = isMember;
        select.innerHTML = `<option value="">Pilih member</option>${array(state.members).map((member) => `<option value="${Number(member.id)}">${escapeHtml(member.name)} · ${escapeHtml(member.phone || '-')}</option>`).join('')}`;
        select.value = isMember && [...select.options].some((option) => option.value === selectedMemberId)
            ? selectedMemberId
            : '';
    }

    const member = array(state.members).find((item) => Number(item.id) === Number(memberId?.value));
    if (memberId && memberId.value && !member) memberId.value = '';
    if (search && member) search.value = reservationMemberLabel(member);
    if (clear) clear.hidden = !member;
    if (triggerLabel) triggerLabel.textContent = member ? reservationMemberLabel(member) : 'Pilih member';
    if (preview) preview.textContent = member
        ? `${member.name} · ${member.phone || 'tanpa nomor telepon'} · Member sejak ${member.member_since ? new Date(`${member.member_since}T00:00:00`).toLocaleDateString('id-ID') : '-'}`
        : 'Pilih member untuk memakai data pelanggan yang sudah terdaftar.';
}

function reservationMemberLabel(member) {
    return `${member.name} · ${member.phone || 'tanpa nomor telepon'}`;
}

function closeReservationMemberSearch() {
    const results = document.getElementById('reservation-member-results');
    const trigger = document.getElementById('reservation-member-trigger');
    if (results) results.hidden = true;
    if (results) results.style.display = '';
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
}

function openReservationMemberSearch() {
    const search = document.getElementById('reservation-member-search');
    if (!search) return;

    search.value = '';
    renderReservationMemberResults();
    requestAnimationFrame(() => search.focus());
}

function renderReservationMemberResults() {
    const search = document.getElementById('reservation-member-search');
    const results = document.getElementById('reservation-member-results');
    const options = document.getElementById('reservation-member-options');
    const memberId = document.getElementById('reservation-member-id');
    const trigger = document.getElementById('reservation-member-trigger');
    if (!search || !results || !options) return;

    const query = search.value.trim().toLocaleLowerCase('id-ID');
    const members = array(state.members)
        .filter((member) => !query || `${member.name || ''} ${member.phone || ''}`.toLocaleLowerCase('id-ID').includes(query))
        .slice(0, 30);
    options.innerHTML = members.length
        ? members.map((member) => `<button type="button" class="reservation-member-option${Number(member.id) === Number(memberId?.value) ? ' selected' : ''}" role="option" aria-selected="${Number(member.id) === Number(memberId?.value)}" data-member-id="${Number(member.id)}">${escapeHtml(reservationMemberLabel(member))}</button>`).join('')
        : '<p class="reservation-member-empty">Member tidak ditemukan.</p>';
    results.hidden = false;
    results.style.display = 'block';
    if (trigger) trigger.setAttribute('aria-expanded', 'true');
}

function selectReservationMember(memberId) {
    const member = array(state.members).find((item) => Number(item.id) === Number(memberId));
    const input = document.getElementById('reservation-member-id');
    const search = document.getElementById('reservation-member-search');
    if (!member || !input || !search) return;

    input.value = String(member.id);
    search.value = reservationMemberLabel(member);
    closeReservationMemberSearch();
    syncReservationCustomerType();
}

function collectReservationPayload(form) {
    const formData = new FormData(form);
    const items = [...document.querySelectorAll('#reservation-items .reservation-item-card')].map((card) => {
        const actualPrice = card.querySelector('.item-price')?.value ?? '';
        const staff = [...card.querySelectorAll('.staff-row')].map((row) => ({
            employee_id: Number(row.querySelector('.item-employee').value),
            role: row.querySelector('.item-staff-role').value,
        }));
        const item = {
            treatment_id: Number(card.querySelector('.item-treatment').value),
            start_time: card.querySelector('.item-time').value,
            notes: card.querySelector('.item-note').value || null,
            staff,
        };
        if (actualPrice !== '') item.actual_price = Number(actualPrice);
        return item;
    });

    const customerType = formData.get('customer_type') || 'guest';
    const payload = {
        customer_type: customerType,
        date: formData.get('date'),
        source: formData.get('source'),
        notes: formData.get('notes') || null,
        items,
    };

    if (customerType === 'member') {
        payload.member_id = Number(formData.get('member_id'));
    } else {
        payload.name = formData.get('name');
        payload.phone = formData.get('phone');
    }

    return payload;
}

function hideConflictPanel() {
    const panel = document.getElementById('reservation-conflict');
    if (!panel) return;
    panel.classList.add('hidden');
    panel.innerHTML = '';
}

function conflictDescription(conflict) {
    const employee = conflict.employee_name || conflict.staff_name || conflict.therapist_name || 'Therapist';
    const existing = conflict.booking_code || conflict.existing_booking_code || conflict.queue_number || 'jadwal lain';
    const start = conflict.conflicting_start_at || conflict.requested_start_at || conflict.start_time || conflict.scheduled_start || conflict.scheduled_start_at || '';
    const end = conflict.conflicting_end_at || conflict.requested_end_at || conflict.end_time || conflict.scheduled_end || conflict.scheduled_end_at || '';
    const clock = (value) => {
        const match = String(value).match(/(?:T|\s)(\d{2}):(\d{2})/);
        return match ? `${match[1]}:${match[2]}` : String(value).slice(0, 5);
    };
    const range = start ? ` (${clock(start)}${end ? `–${clock(end)}` : ''})` : '';
    return `${employee} bertabrakan dengan ${existing}${range}`;
}

function showConflictPanel(error) {
    const panel = document.getElementById('reservation-conflict');
    if (!panel) return;
    const data = error.data || {};
    const conflicts = array(data.conflicts);
    panel.classList.remove('hidden');
    panel.innerHTML = `<strong>Konflik jadwal ditemukan</strong>
        <ul>${conflicts.map((conflict) => `<li>${escapeHtml(conflictDescription(conflict))}</li>`).join('') || '<li>Jadwal therapist bertabrakan dengan reservasi aktif.</li>'}</ul>
        ${data.can_override ? '<label>Alasan override<textarea id="override-reason" placeholder="Wajib diisi untuk audit"></textarea></label><div class="conflict-actions"><button type="button" class="primary" id="confirm-override">Simpan dengan izin</button></div>' : '<p>Anda tidak memiliki izin override. Minta Admin mengubah jadwal atau menyetujui konflik ini.</p>'}`;
    if (data.can_override) {
        panel.querySelector('#confirm-override').onclick = async () => {
            const reason = panel.querySelector('#override-reason').value.trim();
            if (!reason) {
                toast('Alasan override wajib diisi.', true);
                return;
            }
            await submitReservation({
                ...pendingReservationPayload,
                override_conflict: true,
                override_reason: reason,
            });
        };
    }
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function submitReservation(payload) {
    const form = document.getElementById('reservation-form');
    const submit = form?.querySelector('button[type="submit"], footer .primary');
    const openInCashier = reservationLaunchContext === 'cashier';
    if (submit) submit.disabled = true;
    try {
        const result = await api(openInCashier ? '/operasional/kasir/transaksi' : '/operasional/reservasi', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const createdReservationId = Number(result.reservation?.id || result.id);
        document.getElementById('reservation-modal')?.classList.remove('open');
        resetReservationForm();
        setReservationLaunchContext();
        toast(openInCashier ? 'Transaksi baru siap dilanjutkan di kasir.' : (result.message || 'Reservasi berhasil disimpan.'));
        if (!upsertReservation(result.reservation)) {
            await refresh();
        }
        if (openInCashier) {
            openPage('kasir');
            if (createdReservationId) selectCashier(createdReservationId);
        }
    } catch (error) {
        if (error.status === 409 && error.data?.code === 'schedule_conflict') {
            pendingReservationPayload = payload;
            showConflictPanel(error);
        } else {
            toast(error.message, true);
        }
    } finally {
        if (submit) submit.disabled = false;
    }
}

function newIdempotencyKey() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return `salon-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function paymentMethods() {
    return array(state.payment_methods).filter((method) => method.is_active !== false);
}

function paymentModeOptions() {
    const methods = paymentMethods();
    return [
        { key: 'cash', label: 'Cash', methods: methods.filter((method) => Boolean(Number(method.is_cash))) },
        { key: 'card', label: 'Kartu', methods: methods.filter((method) => method.type === 'card') },
        { key: 'bank_transfer', label: 'Transfer', methods: methods.filter((method) => method.type === 'bank_transfer') },
        { key: 'qris', label: 'QRIS', methods: methods.filter((method) => method.type === 'qris') },
    ].filter((option) => option.methods.length);
}

function paymentSourceDetails(option) {
    if (!option || option.key === 'cash') return '';

    const method = option.methods.find((item) => Number(item.id) === Number(selectedPaymentMethodId)) || option.methods[0];
    const sourceLabel = option.key === 'card' ? 'Mesin EDC' : option.key === 'bank_transfer' ? 'Bank tujuan' : 'QRIS tujuan';
    const heading = option.key === 'card' ? 'Informasi kartu' : `Informasi ${sourceLabel.toLowerCase()}`;
    const sourceSelect = `<label>${sourceLabel} *<select class="payment-source-select" aria-label="${sourceLabel}">${option.methods.map((item) => `<option value="${Number(item.id)}" ${Number(item.id) === Number(method.id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}</select></label>`;

    if (option.key === 'card') {
        return `<section class="payment-source-details card-source-details"><h4>${heading}</h4><div class="payment-source-fields">${sourceSelect}<label>Nomor kartu<input class="payment-card-number" inputmode="numeric" maxlength="32" placeholder="Nomor kartu"></label><label>Nomor transaksi<input class="payment-card-reference" maxlength="100" placeholder="Nomor transaksi"></label></div></section>`;
    }

    return `<section class="payment-source-details"><h4>${heading}</h4><div class="payment-source-fields">${sourceSelect}<div class="payment-destination"><span><small>Nama pemilik rekening</small><b>${escapeHtml(method.account_name || '-')}</b></span><span><small>No. rekening tujuan</small><b>${escapeHtml(method.account_number || '-')}</b></span></div></div></section>`;
}

function renderPaymentModeChoices() {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    document.getElementById('payment-method-choices')?.remove();
    const options = paymentModeOptions();
    const canSplit = paymentMethods().length > 1;
    if (!paymentMode || (!options.some((option) => option.key === paymentMode) && paymentMode !== 'split')) {
        paymentMode = options[0]?.key || null;
    }
    const activeOption = options.find((option) => option.key === paymentMode);
    selectedPaymentMethodId = activeOption?.methods.some((method) => Number(method.id) === Number(selectedPaymentMethodId))
        ? selectedPaymentMethodId
        : activeOption?.methods[0]?.id || null;

    const choices = document.createElement('div');
    choices.id = 'payment-method-choices';
    choices.className = 'payment-method-choices';
    choices.innerHTML = `<div class="payment-mode-list">${options.map((option) => `<button type="button" class="payment-mode${paymentMode === option.key ? ' active' : ''}" data-mode="${option.key}"><i></i>${escapeHtml(option.label)}</button>`).join('')}${canSplit ? `<button type="button" class="payment-mode${paymentMode === 'split' ? ' active' : ''}" data-mode="split"><i></i>Split</button>` : ''}</div>${paymentSourceDetails(activeOption)}`;
    container.before(choices);
    choices.querySelectorAll('.payment-mode').forEach((button) => {
        button.onclick = () => {
            paymentMode = button.dataset.mode;
            selectedPaymentMethodId = null;
            renderPaymentModeChoices();
            renderPaymentRowsForMode();
        };
    });
    choices.querySelectorAll('.payment-source-select').forEach((select) => {
        select.onchange = () => {
            selectedPaymentMethodId = Number(select.value);
            renderPaymentModeChoices();
            renderPaymentRowsForMode();
        };
    });
}

function paymentChargeMeta(row) {
    const methodId = Number(row.querySelector('.payment-method')?.value || 0);
    const method = paymentMethods().find((item) => Number(item.id) === methodId);
    const baseAmount = Number(row.querySelector('.payment-amount')?.value || 0);
    const percent = Number(method?.charge_percent || 0);
    const enabled = row.dataset.chargeEnabled === 'true' && !Boolean(Number(method?.is_cash ?? 0)) && percent > 0;
    const amount = enabled ? Math.round(baseAmount * percent / 100) : 0;

    return { method, baseAmount, percent, enabled, amount, total: baseAmount + amount };
}

function syncPaymentCharge(row, resetToDefault = false) {
    const methodId = Number(row.querySelector('.payment-method')?.value || 0);
    const method = paymentMethods().find((item) => Number(item.id) === methodId);
    const control = row.querySelector('.payment-charge-control');
    const toggle = row.querySelector('.payment-charge-toggle');
    const summary = row.querySelector('.payment-charge-summary');
    const percent = Number(method?.charge_percent || 0);
    const chargeable = !Boolean(Number(method?.is_cash ?? 0)) && percent > 0;
    const defaultEnabled = ![false, 0, '0'].includes(method?.charge_default_enabled);

    if (!chargeable) {
        row.dataset.chargeEnabled = 'false';
        if (control) control.hidden = true;
        return paymentChargeMeta(row);
    }

    if (resetToDefault || row.dataset.chargeEnabled === undefined || row.dataset.chargeEnabled === '') {
        row.dataset.chargeEnabled = String(defaultEnabled);
    }
    const charge = paymentChargeMeta(row);
    if (control) control.hidden = false;
    if (toggle) {
        toggle.setAttribute('aria-pressed', String(charge.enabled));
        toggle.classList.toggle('active', charge.enabled);
        toggle.innerHTML = `<span class="material-symbols-outlined" aria-hidden="true">${charge.enabled ? 'toggle_on' : 'toggle_off'}</span> Charge ${percent.toLocaleString('id-ID', { maximumFractionDigits: 4 })}% ${charge.enabled ? 'aktif' : 'nonaktif'}`;
    }
    if (summary) summary.textContent = charge.enabled ? `+ ${money(charge.amount)} · Total ${money(charge.total)}` : 'Charge tidak dipakai';

    return charge;
}

function addPaymentRow(values = {}) {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    const methods = paymentMethods();
    const selectedMethod = methods.find((method) => Number(method.id) === Number(values.payment_method_id));
    const row = document.createElement('div');
    row.className = `payment-row${values.fixed_method ? ' fixed-method' : ''}${values.fixed_method && selectedMethod?.type === 'card' ? ' has-card-details' : ''}`;
    row.dataset.autoBalance = values.auto_balance ? 'true' : 'false';
    row.dataset.autoTendered = 'true';
    row.dataset.chargeEnabled = values.charge_enabled === undefined ? '' : String(Boolean(values.charge_enabled));
    row.innerHTML = `<label>Metode<select class="payment-method" required>${methods.map((method) => `<option value="${Number(method.id)}" ${Number(method.id) === Number(values.payment_method_id) ? 'selected' : ''}>${escapeHtml(method.name)}</option>`).join('')}</select></label>
        <label>Nominal pembayaran<input class="payment-amount" type="number" min="1" step="1" required value="${Number(values.amount || 0)}"></label>
        <label class="payment-tendered-label" hidden>Uang diterima<input class="payment-tendered" type="number" min="1" step="1" value="${Number(values.tendered_amount || values.amount || 0)}"></label>
        <label class="payment-reference-label">Referensi<input class="payment-reference" placeholder="Opsional"></label>
        <div class="payment-charge-control" hidden><button type="button" class="payment-charge-toggle" aria-pressed="false"></button><small class="payment-charge-summary"></small></div>
        <button type="button" class="icon-button remove-payment" aria-label="Hapus pembayaran"><span class="material-symbols-outlined">close</span></button>`;
    container.appendChild(row);
    row.querySelector('.payment-amount').addEventListener('input', () => {
        row.dataset.autoBalance = 'false';
        syncCashTendered(row);
        syncPaymentCharge(row);
        syncSplitAutoBalance();
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-tendered').addEventListener('input', () => {
        row.dataset.autoTendered = 'false';
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-method').disabled = Boolean(values.fixed_method);
    row.querySelector('.payment-method').addEventListener('change', () => {
        syncPaymentReference(row, true);
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-charge-toggle').addEventListener('click', () => {
        row.dataset.chargeEnabled = String(row.dataset.chargeEnabled !== 'true');
        syncPaymentCharge(row);
        syncCashTendered(row);
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-reference').addEventListener('input', updatePaymentReconciliation);
    row.querySelector('.remove-payment').onclick = () => {
        if (container.children.length <= 1) {
            toast('Minimal ada satu metode pembayaran.', true);
            return;
        }
        row.remove();
        syncSplitAutoBalance();
        updatePaymentReconciliation();
    };
    syncPaymentReference(row, values.charge_enabled === undefined);
    updatePaymentReconciliation();
}

function syncPaymentReference(row, resetCharge = false) {
    const methodId = Number(row.querySelector('.payment-method')?.value || 0);
    const method = paymentMethods().find((item) => Number(item.id) === methodId);
    const input = row.querySelector('.payment-reference');
    const label = row.querySelector('.payment-reference-label');
    const tenderedInput = row.querySelector('.payment-tendered');
    const tenderedLabel = row.querySelector('.payment-tendered-label');
    const isCash = Boolean(Number(method?.is_cash ?? 0));
    input.required = false;
    input.placeholder = 'Opsional';
    if (label) label.firstChild.textContent = 'Referensi (opsional)';
    row.classList.toggle('is-cash', isCash);
    tenderedLabel.hidden = !isCash;
    tenderedInput.disabled = !isCash;
    tenderedInput.required = isCash;
    if (isCash) {
        syncCashTendered(row);
    }
    syncPaymentCharge(row, resetCharge);
}

function syncCashTendered(row) {
    const tenderedInput = row.querySelector('.payment-tendered');
    if (!row.classList.contains('is-cash') || !tenderedInput || row.dataset.autoTendered !== 'true') return;

    tenderedInput.value = row.querySelector('.payment-amount')?.value || 0;
}

function splitRemainingAmount(excludedRow = null) {
    const allocated = [...document.querySelectorAll('.payment-row')]
        .filter((row) => row !== excludedRow)
        .reduce((sum, row) => sum + Number(row.querySelector('.payment-amount')?.value || 0), 0);

    return Math.max(0, selectedTotal() - allocated);
}

function syncSplitAutoBalance() {
    if (paymentMode !== 'split') return;

    const autoRow = [...document.querySelectorAll('.payment-row')]
        .find((row) => row.dataset.autoBalance === 'true');
    if (!autoRow) return;

    const amountInput = autoRow.querySelector('.payment-amount');
    if (!amountInput) return;
    amountInput.value = splitRemainingAmount(autoRow);
    syncCashTendered(autoRow);
}

function resetPaymentRows() {
    paymentIdempotencyKey = newIdempotencyKey();
    paymentMode = paymentModeOptions()[0]?.key || null;
    selectedPaymentMethodId = null;
    renderPaymentModeChoices();
    renderPaymentRowsForMode();
}

function renderPaymentRowsForMode() {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    container.innerHTML = '';
    if (paymentMode === 'split') {
        addPaymentRow({ amount: selectedTotal() });
    } else if (selectedPaymentMethodId) {
        addPaymentRow({ payment_method_id: selectedPaymentMethodId, amount: selectedTotal(), fixed_method: true });
    }
    document.getElementById('add-payment-row').hidden = paymentMode !== 'split';
}

function updatePaymentReconciliation() {
    const total = selectedTotal();
    const rows = [...document.querySelectorAll('.payment-row')];
    const charges = rows.map((row) => syncPaymentCharge(row));
    const entered = charges.reduce((sum, charge) => sum + charge.total, 0);
    const totalCharge = charges.reduce((sum, charge) => sum + charge.amount, 0);
    const payable = total + totalCharge;
    const cashRows = [...document.querySelectorAll('.payment-row.is-cash')];
    const cashTendered = cashRows.reduce((sum, row) => sum + Number(row.querySelector('.payment-tendered')?.value || 0), 0);
    const cashAllocated = cashRows.reduce((sum, row) => sum + paymentChargeMeta(row).total, 0);
    const change = Math.max(0, cashTendered - cashAllocated);
    const invalidCashTender = cashRows.some((row) => Number(row.querySelector('.payment-tendered')?.value || 0) < paymentChargeMeta(row).total);
    const difference = payable - entered;
    const panel = document.querySelector('.payment-reconciliation');
    document.getElementById('payment-base-total').textContent = money(total);
    document.getElementById('payment-entered').textContent = money(entered);
    document.getElementById('payment-difference').textContent = money(difference);
    document.getElementById('payment-total').textContent = money(payable);
    const chargeTotal = document.getElementById('payment-charge-total');
    if (chargeTotal) {
        chargeTotal.hidden = totalCharge <= 0;
        chargeTotal.querySelector('b').textContent = money(totalCharge);
    }
    const changeElement = document.getElementById('payment-change');
    if (changeElement) {
        changeElement.hidden = !cashRows.length;
        changeElement.querySelector('b').textContent = money(change);
    }
    panel?.classList.toggle('has-difference', difference !== 0);
    const button = document.getElementById('complete-payment');
    if (button) {
        button.disabled = difference !== 0 || entered <= 0 || !paymentMethods().length || invalidCashTender;
        button.title = invalidCashTender ? 'Uang tunai yang diterima tidak boleh kurang dari nominal pembayaran.' : '';
    }
}

function quickForm(title, fields, submit) {
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><h2>${escapeHtml(title)}</h2><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div><form><div class="quick-fields">${fields.map(([name, label, type, options, value]) => `<label>${escapeHtml(label)}${type === 'select' ? `<select name="${escapeHtml(name)}">${array(options).map((option) => {
        const parts = String(option).split('|');
        const optionValue = parts.length > 1 ? parts[0] : option;
        return `<option value="${escapeHtml(optionValue)}" ${String(optionValue) === String(value ?? '') ? 'selected' : ''}>${escapeHtml(parts[1] || parts[0])}</option>`;
    }).join('')}</select>` : `<input name="${escapeHtml(name)}" type="${escapeHtml(type)}" value="${escapeHtml(value ?? '')}" ${String(label).includes('(opsional)') ? '' : 'required'}>`}</label>`).join('')}</div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary">Simpan</button></footer></form></div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelector('form').onsubmit = async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector('button[type="submit"], footer .primary');
        button.disabled = true;
        try {
            const result = await submit(Object.fromEntries(new FormData(event.currentTarget)));
            wrapper.remove();
            toast(result.message);
            await refresh();
        } catch (error) {
            button.disabled = false;
            toast(error.message, true);
        }
    };

    return wrapper;
}

function populateSelects() {
    const memberSelect = document.getElementById('reservation-member-id');
    if (memberSelect?.tagName === 'SELECT') {
        const selected = memberSelect.value;
        memberSelect.innerHTML = `<option value="">Pilih member</option>${array(state.members).map((member) => `<option value="${Number(member.id)}">${escapeHtml(member.name)} · ${escapeHtml(member.phone || '-')}</option>`).join('')}`;
        memberSelect.value = [...memberSelect.options].some((option) => option.value === selected) ? selected : '';
        syncReservationCustomerType();
    }
    syncReservationCustomerType();

    const employeeFilter = document.getElementById('reservation-filter-employee');
    if (employeeFilter) {
        const selected = employeeFilter.value;
        employeeFilter.innerHTML = `<option value="">Semua therapist</option>${serviceProviders().map((employee) => `<option value="${Number(employee.id)}">${escapeHtml(employee.name)}</option>`).join('')}`;
        employeeFilter.value = selected;
    }

    const promo = document.getElementById('discount');
    if (promo) {
        const selected = promo.value;
        promo.innerHTML = `<option value="0">Tidak menggunakan diskon</option>${array(state.promotions).map((promotion) => `<option value="${Number(promotion.discount_percent)}">${escapeHtml(promotion.name)} · ${Number(promotion.discount_percent)}%</option>`).join('')}`;
        promo.value = [...promo.options].some((option) => option.value === selected) ? selected : '0';
    }
}

function attendanceDateFromMonth(month, day) {
    return `${month}-${String(day).padStart(2, '0')}`;
}

function therapistInitial(name) {
    return String(name || '?').trim().charAt(0).toLocaleUpperCase('id-ID') || '?';
}

function renderTherapistAttendanceCalendar() {
    const box = document.getElementById('therapist-attendance-calendar');
    if (!box || !therapistAttendanceDate) return;

    const month = therapistAttendanceMonth || therapistAttendanceDate.slice(0, 7);
    const [year, monthNumber] = month.split('-').map(Number);
    if (!year || !monthNumber) return;

    const monthStart = new Date(year, monthNumber - 1, 1, 12);
    const daysInMonth = new Date(year, monthNumber, 0, 12).getDate();
    const firstWeekday = (monthStart.getDay() + 6) % 7;
    const monthLabel = new Intl.DateTimeFormat('id-ID', {
        month: 'long', year: 'numeric',
    }).format(monthStart);
    const weekdayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    const cells = Array.from({ length: firstWeekday + daysInMonth }, (_, index) => {
        if (index < firstWeekday) return '<span class="therapist-attendance-calendar-empty" aria-hidden="true"></span>';

        const day = index - firstWeekday + 1;
        const date = attendanceDateFromMonth(month, day);
        const offTherapists = array(therapistAttendanceOffByDate[date]);
        const names = offTherapists.map((therapist) => therapist.name).filter(Boolean);
        const isSelected = date === therapistAttendanceDate;
        const isToday = date === localDate();
        const marker = offTherapists.length === 1
            ? therapistInitial(offTherapists[0].name)
            : `+${offTherapists.length}`;
        const label = names.length
            ? `${day} ${monthLabel}: ${names.join(', ')} libur`
            : `${day} ${monthLabel}`;
        const tooltip = names.length
            ? `<span class="therapist-attendance-calendar-tooltip" role="tooltip"><b>Terapis libur</b><span>${escapeHtml(names.join(', '))}</span></span>`
            : '';

        return `<button type="button" class="therapist-attendance-calendar-day${isSelected ? ' is-selected' : ''}${isToday ? ' is-today' : ''}${names.length ? ' has-off' : ''}" data-attendance-date="${date}" aria-label="${escapeHtml(label)}"><span class="therapist-attendance-calendar-day-value">${names.length ? escapeHtml(marker) : day}</span>${tooltip}</button>`;
    }).join('');

    box.innerHTML = `<div class="therapist-attendance-calendar-head"><div><h3>Kalender kehadiran</h3><p>Penanda terapis yang libur</p></div><div class="therapist-attendance-calendar-nav"><button type="button" data-attendance-calendar-month="previous" aria-label="Bulan sebelumnya"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button><strong>${escapeHtml(monthLabel)}</strong><button type="button" data-attendance-calendar-month="next" aria-label="Bulan berikutnya"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button></div></div><div class="therapist-attendance-calendar-weekdays" aria-hidden="true">${weekdayLabels.map((label) => `<span>${label}</span>`).join('')}</div><div class="therapist-attendance-calendar-grid">${cells}</div><p class="therapist-attendance-calendar-note"><i aria-hidden="true">A</i> Satu terapis libur &middot; <i aria-hidden="true">+2</i> Dua atau lebih</p>`;

    box.querySelectorAll('[data-attendance-date]').forEach((button) => {
        button.addEventListener('click', () => loadTherapistAttendance(button.dataset.attendanceDate));
    });
    box.querySelectorAll('[data-attendance-calendar-month]').forEach((button) => {
        button.addEventListener('click', () => {
            const nextMonth = new Date(year, monthNumber - 1 + (button.dataset.attendanceCalendarMonth === 'next' ? 1 : -1), 1, 12);
            const nextYear = nextMonth.getFullYear();
            const nextMonthNumber = nextMonth.getMonth() + 1;
            const nextMonthKey = `${nextYear}-${String(nextMonthNumber).padStart(2, '0')}`;
            const selectedDay = Number(therapistAttendanceDate.slice(-2));
            const nextDay = Math.min(selectedDay, new Date(nextYear, nextMonthNumber, 0, 12).getDate());
            loadTherapistAttendance(attendanceDateFromMonth(nextMonthKey, nextDay));
        });
    });
}

function renderTherapistAttendance() {
    const box = document.getElementById('therapist-attendance');
    if (!box || !therapistAttendanceDate) return;
    const dateLabel = new Intl.DateTimeFormat('id-ID', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    }).format(new Date(`${therapistAttendanceDate}T12:00:00`));
    const editable = canManageTherapistAttendance;
    box.innerHTML = `<div class="card-head therapist-attendance-head"><div><h3>Kehadiran terapis</h3><p>${escapeHtml(dateLabel)}</p></div><label>Tanggal<input type="date" id="therapist-attendance-date" value="${escapeHtml(therapistAttendanceDate)}"></label></div><form id="therapist-attendance-form"><div class="therapist-attendance-table"><div class="therapist-attendance-row therapist-attendance-table-head"><span>TERAPIS</span><span>STATUS</span></div>${therapistAttendance.map((therapist) => `<label class="therapist-attendance-row"><span><b>${escapeHtml(therapist.name)}</b><small>${escapeHtml(therapist.specialty || 'Terapis')}</small></span><select data-employee-id="${Number(therapist.employee_id)}" ${editable ? '' : 'disabled'}><option value="present" ${therapist.status === 'present' ? 'selected' : ''}>Masuk</option><option value="off" ${therapist.status === 'off' ? 'selected' : ''}>Libur</option></select></label>`).join('')}</div>${editable ? '<footer><button type="submit" class="primary">Simpan kehadiran</button></footer>' : ''}</form>`;
    box.querySelector('#therapist-attendance-date')?.addEventListener('change', (event) => {
        loadTherapistAttendance(event.currentTarget.value);
    });
    box.querySelector('#therapist-attendance-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = event.currentTarget.querySelector('[type="submit"]');
        const statuses = [...box.querySelectorAll('select[data-employee-id]')].map((select) => ({
            employeeId: Number(select.dataset.employeeId),
            status: select.value,
        }));
        const changes = statuses.filter((item) => therapistAttendance.some((therapist) => (
            Number(therapist.employee_id) === item.employeeId && therapist.status !== item.status
        )));
        if (!changes.length) {
            toast('Tidak ada perubahan kehadiran.');
            return;
        }
        submit.disabled = true;
        try {
            await Promise.all(changes.map((item) => api(`/operasional/therapist-kehadiran/${item.employeeId}`, {
                method: 'PUT',
                body: JSON.stringify({ date: therapistAttendanceDate, status: item.status }),
            })));
            toast('Kehadiran terapis diperbarui.');
            await loadTherapistAttendance(therapistAttendanceDate);
            renderReservations();
        } catch (error) {
            toast(error.message, true);
            submit.disabled = false;
        }
    });
    renderTherapistAttendanceCalendar();
}

function openTherapistAttendanceManager() {
    if (!therapistAttendanceDate) return;
    const dateLabel = new Intl.DateTimeFormat('id-ID', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    }).format(new Date(`${therapistAttendanceDate}T12:00:00`));
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal therapist-attendance-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><div><h2>Atur kehadiran</h2><p>${escapeHtml(dateLabel)}</p></div><button type="button" class="quick-close" aria-label="Tutup">×</button></div><form><p class="therapist-attendance-modal-note">Centang therapist yang libur. Therapist yang tidak dicentang dianggap masuk.</p><div class="therapist-attendance-form-list">${therapistAttendance.map((therapist) => `<label><input type="checkbox" value="${Number(therapist.employee_id)}" ${therapist.status === 'off' ? 'checked' : ''}><span>${escapeHtml(therapist.name)}</span></label>`).join('')}</div><footer><button type="button" class="secondary quick-close">Batal</button><button type="submit" class="primary">Simpan</button></footer></form></div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.addEventListener('click', () => wrapper.remove());
    });
    wrapper.addEventListener('click', (event) => {
        if (event.target === wrapper) wrapper.remove();
    });
    wrapper.querySelector('form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = event.currentTarget.querySelector('[type="submit"]');
        const offIds = new Set([...wrapper.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value)));
        const changes = therapistAttendance.filter((therapist) => (
            (therapist.status === 'off') !== offIds.has(Number(therapist.employee_id))
        ));
        if (!changes.length) return wrapper.remove();

        submit.disabled = true;
        try {
            await Promise.all(changes.map((therapist) => api(`/operasional/therapist-kehadiran/${Number(therapist.employee_id)}`, {
                method: 'PUT',
                body: JSON.stringify({
                    date: therapistAttendanceDate,
                    status: offIds.has(Number(therapist.employee_id)) ? 'off' : 'present',
                }),
            })));
            wrapper.remove();
            toast('Kehadiran terapis diperbarui.');
            await loadTherapistAttendance(therapistAttendanceDate);
            renderReservations();
        } catch (error) {
            toast(error.message, true);
            submit.disabled = false;
        }
    });
}

async function loadTherapistAttendance(date) {
    if (!date) return;
    try {
        const month = date.slice(0, 7);
        const data = await api(`/operasional/therapist-kehadiran?date=${encodeURIComponent(date)}&month=${encodeURIComponent(month)}`);
        therapistAttendanceDate = data.date || date;
        therapistAttendance = array(data.therapists);
        therapistAttendanceMonth = data.month || month;
        therapistAttendanceOffByDate = data.off_by_date && typeof data.off_by_date === 'object'
            ? data.off_by_date
            : {};
        renderTherapistAttendance();
        if (calendarMode === 'day') renderReservations();
    } catch (error) {
        // Jadwal tetap dapat digunakan bila endpoint tambahan ini belum tersedia.
    }
}

function initReservationControls() {
    const date = document.getElementById('reservation-calendar-date');
    const employee = document.getElementById('reservation-filter-employee');
    const status = document.getElementById('reservation-filter-status');
    if (date) date.value = localDate();
    [date, employee, status].filter(Boolean).forEach((filter) => filter.addEventListener('change', () => {
        reservationStatusGroup = null;
        renderReservations();
        if (filter === date) loadTherapistAttendance(date.value);
    }));

    const moveWeek = (direction) => {
        if (!date?.value) return;
        const selected = new Date(`${date.value}T12:00:00`);
        selected.setDate(selected.getDate() + (direction * 7));
        date.value = `${selected.getFullYear()}-${String(selected.getMonth() + 1).padStart(2, '0')}-${String(selected.getDate()).padStart(2, '0')}`;
        reservationStatusGroup = null;
        renderReservations();
        loadTherapistAttendance(date.value);
    };
    document.getElementById('calendar-prev')?.addEventListener('click', () => moveWeek(-1));
    document.getElementById('calendar-next')?.addEventListener('click', () => moveWeek(1));
    document.getElementById('calendar-today')?.addEventListener('click', () => {
        if (!date) return;
        date.value = localDate();
        reservationStatusGroup = null;
        renderReservations();
        loadTherapistAttendance(date.value);
    });

    const setCalendarMode = (mode) => {
        calendarMode = mode === 'day' ? 'day' : 'week';
        document.querySelectorAll('[data-calendar-mode]').forEach((item) => {
            const active = item.dataset.calendarMode === calendarMode;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        renderReservations();
    };
    loadTherapistAttendance(date?.value);
    document.querySelectorAll('[data-calendar-mode]').forEach((tab) => {
        tab.addEventListener('click', () => setCalendarMode(tab.dataset.calendarMode));
    });
}

function initActivityControls() {
    ['activity-filter-date', 'activity-filter-user', 'activity-filter-action'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', renderActivity);
    });
    document.getElementById('activity-search')?.addEventListener('input', renderActivity);
}

document.querySelectorAll('#navigation [data-page]').forEach((button) => {
    button.onclick = () => openPage(button.dataset.page);
});
document.querySelectorAll('.go-reservation').forEach((button) => {
    button.onclick = () => openPage('reservasi-antrean');
});
document.querySelectorAll('.go-therapist-attendance').forEach((button) => {
    button.onclick = () => openPage('kehadiran-terapis');
});
document.querySelectorAll('.go-stock').forEach((button) => {
    button.onclick = () => openPage('stok');
});
if (location.hash) openPage(location.hash.slice(1));

document.querySelectorAll('.dashboard-metric').forEach((card) => {
    card.addEventListener('click', () => openDashboardMetric(card));
    card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openDashboardMetric(card);
        }
    });
});

document.querySelectorAll('.open-reservation').forEach((button) => {
    button.onclick = () => {
        openReservationForm();
    };
});
document.addEventListener('click', (event) => {
    if (!event.target.closest('.cashier-create-transaction')) return;
    openReservationForm({ context: 'cashier', date: localDate(), source: 'walk_in' });
});
document.getElementById('add-reservation-item')?.addEventListener('click', () => addReservationItem());
document.getElementById('reservation-date')?.addEventListener('change', () => {
    document.querySelectorAll('#reservation-items .reservation-item-card').forEach((card) => refreshReservationTherapistAvailability(card));
});
document.addEventListener('click', (event) => {
    if (!event.target.closest('.therapist-picker-label')) closeReservationTherapistPickers();
    if (!event.target.closest('.treatment-picker-label')) closeReservationTreatmentPickers();
});
document.getElementById('open-product')?.addEventListener('click', () => modal('product-modal'));
document.getElementById('open-product-import')?.addEventListener('click', () => {
    const form = document.getElementById('product-import-form');
    const result = document.getElementById('product-import-result');
    const fileName = document.getElementById('product-import-file-name');
    form?.reset();
    if (result) {
        result.hidden = true;
        result.innerHTML = '';
        result.classList.remove('has-issues');
    }
    if (fileName) fileName.textContent = 'Format .xlsx atau .csv, maksimal 5 MB';
    modal('product-import-modal');
});
document.getElementById('product-import-file')?.addEventListener('change', (event) => {
    const fileName = document.getElementById('product-import-file-name');
    const file = event.currentTarget.files?.[0];
    if (fileName) fileName.textContent = file?.name || 'Format .xlsx atau .csv, maksimal 5 MB';
});
document.getElementById('open-cash-entry')?.addEventListener('click', openCashEntryForm);
document.getElementById('open-payroll')?.addEventListener('click', openPayrollForm);
document.querySelectorAll('[data-sales-view]').forEach((button) => {
    button.addEventListener('click', () => setSalesView(button.dataset.salesView));
});
document.getElementById('sales-search')?.addEventListener('input', () => {
    clearTimeout(salesSearchTimer);
    salesSearchTimer = setTimeout(() => loadSalesPage(1).catch((error) => toast(error.message, true)), 250);
});
document.getElementById('sales-payment-filter')?.addEventListener('change', () => loadSalesPage(1).catch((error) => toast(error.message, true)));
document.getElementById('member-search')?.addEventListener('input', () => {
    clearTimeout(memberSearchTimer);
    memberSearchTimer = setTimeout(() => loadMembersPage(1).catch((error) => toast(error.message, true)), 250);
});
document.getElementById('stock-history-from')?.addEventListener('change', () => loadStockHistoryPage(1).catch((error) => toast(error.message, true)));
document.getElementById('stock-history-to')?.addEventListener('change', () => loadStockHistoryPage(1).catch((error) => toast(error.message, true)));
document.getElementById('cash-entry-type-filter')?.addEventListener('change', renderCashEntryHistory);
document.getElementById('cash-entry-search')?.addEventListener('input', renderCashEntryHistory);
document.getElementById('cash-entry-from')?.addEventListener('change', renderCashEntryHistory);
document.getElementById('cash-entry-to')?.addEventListener('change', renderCashEntryHistory);
document.getElementById('open-payment')?.addEventListener('click', () => {
    if (!selectedReservation) {
        toast('Pilih antrean terlebih dahulu.', true);
        return;
    }
    document.getElementById('inline-payment')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});
document.querySelectorAll('.close-modal').forEach((button) => {
    button.onclick = () => closeModal(button);
});

document.getElementById('export-schedule')?.addEventListener('click', () => {
    const date = document.getElementById('reservation-calendar-date')?.value || localDate();
    window.location.assign(`/operasional/reservasi/ekspor?date=${encodeURIComponent(date)}`);
});

document.getElementById('export-stock-history')?.addEventListener('click', () => {
    const today = localDate();
    const from = document.getElementById('stock-history-from')?.value || `${today.slice(0, 8)}01`;
    const to = document.getElementById('stock-history-to')?.value || today;
    if (from > to) {
        toast('Tanggal akhir tidak boleh sebelum tanggal awal.', true);
        return;
    }
    window.location.assign(`/operasional/produk/riwayat-ekspor?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
});

document.getElementById('discount')?.addEventListener('change', () => {
    if (selectedReservation) selectCashier(selectedReservation);
});
document.getElementById('manual-discount')?.addEventListener('input', () => {
    if (selectedReservation) selectCashier(selectedReservation);
});
document.getElementById('add-extra')?.addEventListener('click', openCashierAddPicker);
document.addEventListener('click', (event) => {
    const button = event.target.closest('.remove-cashier-product');
    if (!button) return;
    if (!selectedReservation) return;
    api(`/operasional/reservasi/${Number(selectedReservation)}/produk/${Number(button.dataset.id)}`, {
        method: 'DELETE',
    }).then(async (result) => {
        toast(result.message);
        await refresh();
        selectCashier(selectedReservation);
    }).catch((error) => toast(error.message, true));
});

document.getElementById('reservation-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideConflictPanel();
    const payload = collectReservationPayload(event.currentTarget);
    await submitReservation(payload);
});

document.querySelectorAll('#reservation-form [name="customer_type"]').forEach((input) => {
    input.addEventListener('change', syncReservationCustomerType);
});
document.getElementById('reservation-member-id')?.addEventListener('change', syncReservationCustomerType);
function syncReservationMemberSearch() {
    const search = document.getElementById('reservation-member-search');
    const memberId = document.getElementById('reservation-member-id');
    const clear = document.getElementById('reservation-member-clear');
    const query = search?.value.trim().toLocaleLowerCase('id-ID') || '';
    const member = array(state.members).find((item) => reservationMemberLabel(item).toLocaleLowerCase('id-ID') === query);

    if (member) {
        selectReservationMember(member.id);
        return;
    }

    if (memberId) memberId.value = '';
    if (clear) clear.hidden = true;
    syncReservationCustomerType();
    renderReservationMemberResults();
}
document.getElementById('reservation-member-search')?.addEventListener('input', syncReservationMemberSearch);
document.getElementById('reservation-member-search')?.addEventListener('focus', renderReservationMemberResults);
document.getElementById('reservation-member-trigger')?.addEventListener('click', () => {
    const results = document.getElementById('reservation-member-results');
    if (results?.hidden) openReservationMemberSearch();
    else closeReservationMemberSearch();
});
document.getElementById('reservation-member-results')?.addEventListener('click', (event) => {
    const option = event.target.closest('[data-member-id]');
    if (option) selectReservationMember(option.dataset.memberId);
});
document.addEventListener('click', (event) => {
    if (!event.target.closest('.reservation-member-combobox')) closeReservationMemberSearch();
});

document.getElementById('product-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const dialog = form.closest('.modal');
    const fields = form.querySelectorAll('input,select');
    try {
        const result = await api('/operasional/produk', {
            method: 'POST',
            body: JSON.stringify({
                name: fields[0].value,
                category: fields[1].value,
                stock: fields[2].value,
                unit: fields[3].value,
                minimum_stock: fields[4].value,
                selling_price: fields[5].value,
                cost_price: fields[6].value,
            }),
        });
        dialog.classList.remove('open');
        form.reset();
        toast(result.message);
        await refresh();
    } catch (error) {
        toast(error.message, true);
    }
});

document.getElementById('product-import-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const file = form.querySelector('[name="file"]')?.files?.[0];
    const submit = document.getElementById('submit-product-import');
    const resultBox = document.getElementById('product-import-result');
    if (!file || !submit || !resultBox) {
        toast('Pilih file Excel yang akan diimpor.', true);
        return;
    }

    const originalLabel = submit.innerHTML;
    submit.disabled = true;
    submit.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">progress_activity</span> Memproses...';
    resultBox.hidden = true;

    try {
        const payload = new FormData();
        payload.append('file', file);
        const result = await api('/operasional/produk/import', {
            method: 'POST',
            body: payload,
        });
        const issues = array(result.issues);
        resultBox.classList.toggle('has-issues', Number(result.skipped) > 0);
        resultBox.innerHTML = `<strong>${escapeHtml(result.message)}</strong>${issues.length ? `<ul>${issues.map((issue) => `<li>Baris ${Number(issue.row)}: ${escapeHtml(issue.message)}</li>`).join('')}</ul>` : ''}${Number(result.issues_total) > issues.length ? `<p>Masih ada ${Number(result.issues_total) - issues.length} catatan lain yang tidak ditampilkan.</p>` : ''}`;
        resultBox.hidden = false;
        toast(result.message, Number(result.imported) === 0);
        if (Number(result.imported) > 0) await refresh();
    } catch (error) {
        toast(error.message, true);
    } finally {
        submit.disabled = false;
        submit.innerHTML = originalLabel;
    }
});

document.getElementById('product-edit-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const id = form.querySelector('[name="id"]')?.value;
    if (!id) return;

    try {
        const result = await api(`/operasional/produk/${id}`, {
            method: 'PATCH',
            body: JSON.stringify({
                name: form.querySelector('[name="name"]')?.value,
                category: form.querySelector('[name="category"]')?.value,
                unit_id: Number(form.querySelector('[name="unit_id"]')?.value),
                minimum_stock: form.querySelector('[name="minimum_stock"]')?.value,
                selling_price: Number(form.querySelector('[name="selling_price"]')?.value),
                cost_price: Number(form.querySelector('[name="cost_price"]')?.value),
                is_active: Number(form.querySelector('[name="is_active"]')?.value),
                description: form.querySelector('[name="description"]')?.value,
            }),
        });
        form.closest('.modal')?.classList.remove('open');
        toast(result.message);
        await refresh();
    } catch (error) {
        toast(error.message, true);
    }
});

function prepareInlinePayment() {
    const modalElement = document.getElementById('payment-modal');
    const cashier = document.getElementById('cashier-receipt');
    if (!modalElement || !cashier) return;

    const description = document.getElementById('payment-description');
    const total = modalElement.querySelector('.payment-total');
    const splitHead = modalElement.querySelector('.split-payment-head');
    const rows = document.getElementById('payment-rows');
    const reconciliation = modalElement.querySelector('.payment-reconciliation');
    const stockImpact = modalElement.querySelector('.stock-impact');
    const completeButton = document.getElementById('complete-payment');
    if (!description || !total || !splitHead || !rows || !reconciliation || !stockImpact || !completeButton) return;

    const inline = document.createElement('section');
    inline.id = 'inline-payment';
    inline.className = 'inline-payment';
    const heading = document.createElement('div');
    heading.className = 'inline-payment-head';
    heading.innerHTML = '<h3>Pembayaran</h3>';
    heading.append(description);
    inline.append(heading, total, splitHead, rows, reconciliation, stockImpact, completeButton);
    cashier.append(inline);
    completeButton.textContent = 'Proses transaksi';
    document.getElementById('open-payment').hidden = true;
    modalElement.remove();
}

prepareInlinePayment();
document.getElementById('add-payment-row')?.addEventListener('click', () => {
    const remaining = splitRemainingAmount();
    if (remaining <= 0) {
        toast('Nominal pembayaran sudah lengkap. Ubah salah satu baris bila ingin membagi ulang.', true);
        return;
    }

    addPaymentRow({ amount: remaining, auto_balance: true });
});
document.getElementById('complete-payment')?.addEventListener('click', async () => {
    const button = document.getElementById('complete-payment');
    const cardNumber = document.querySelector('.payment-card-number')?.value.trim() || null;
    const cardReference = document.querySelector('.payment-card-reference')?.value.trim() || null;
    const payments = [...document.querySelectorAll('.payment-row')].map((row) => {
        const charge = paymentChargeMeta(row);

        return {
            payment_method_id: Number(row.querySelector('.payment-method').value),
            amount: charge.baseAmount,
            charge_enabled: charge.enabled,
            charge_percent: charge.percent,
            charge_amount: charge.amount,
            tendered_amount: row.classList.contains('is-cash')
                ? Number(row.querySelector('.payment-tendered').value)
                : charge.total,
            reference_number: cardReference || row.querySelector('.payment-reference').value.trim() || null,
            notes: cardNumber ? `Nomor kartu: ${cardNumber}` : null,
        };
    });
    button.disabled = true;
    try {
        const reservation = array(state.reservations).find((item) => Number(item.id) === Number(selectedReservation));
        const receipt = receiptPayload({ total: selectedTotal() }, reservation, reservationProductItems(reservation), payments);
        const result = await api('/operasional/pembayaran', {
            method: 'POST',
            body: JSON.stringify({
                reservation_id: selectedReservation,
                discount_percent: String(selectedDiscount()),
                ...(Number(document.getElementById('manual-discount')?.value || 0) > 0
                    ? { manual_discount_percent: String(document.getElementById('manual-discount').value) }
                    : {}),
                payments,
                idempotency_key: paymentIdempotencyKey,
            }),
        });
        toast(`${result.message}: ${compactInvoiceNumber(result.number || result.transaction_number || '')}`.trim());
        receipt.number = compactInvoiceNumber(result.number || result.transaction_number || receipt.number);
        receipt.transactionId = Number(result.id || receipt.transactionId || 0) || null;
        receipt.total = Number(result.total || receipt.total);
        receipt.baseTotal = Number(result.base_total || receipt.baseTotal);
        receipt.paymentCharge = Number(result.payment_charge_amount || receipt.paymentCharge);
        receipt.change = Number(result.change_amount || receipt.change || 0);
        receipt.cashier = result.cashier_name || receipt.cashier;
        const ratedTherapists = array(result.therapists);
        receipt.therapists = ratedTherapists.map((therapist) => therapist.name).filter(Boolean);
        openReceiptPrintChoice(receipt, { ratingTherapists: ratedTherapists });
        selectedReservation = null;
        paymentIdempotencyKey = null;
        await refresh();
    } catch (error) {
        button.disabled = false;
        toast(error.message, true);
    }
});

const treatmentAdd = document.getElementById('open-treatment');
if (treatmentAdd) {
    treatmentAdd.onclick = () => quickForm('Tambah treatment', [
        ['name', 'Nama', 'text'],
        ['category', 'Kategori', 'text'],
        ['duration_minutes', 'Durasi (menit)', 'number'],
        ['price', 'Harga normal', 'number'],
        ['commission_percent', 'Komisi (%)', 'number'],
    ], (data) => api('/operasional/treatment', { method: 'POST', body: JSON.stringify(data) }));
}

document.getElementById('open-employee')?.addEventListener('click', () => quickForm('Tambah pegawai', [
    ['name', 'Nama', 'text'],
    ['position', 'Posisi', 'text'],
    ['specialty', 'Spesialisasi', 'text'],
    ['is_service_provider', 'Dapat mengerjakan layanan', 'select', ['1|Ya', '0|Tidak'], 1],
], (data) => api('/operasional/pegawai', {
    method: 'POST',
    body: JSON.stringify({
        ...data,
        is_service_provider: Number(data.is_service_provider),
        active: 1,
    }),
})));

const memberAdd = document.getElementById('open-member');
if (memberAdd) {
    memberAdd.onclick = () => quickForm('Member baru', [
        ['name', 'Nama pelanggan', 'text'],
        ['phone', 'Nomor telepon', 'text'],
        ['email', 'Email (opsional)', 'email'],
    ], (data) => api('/operasional/member', { method: 'POST', body: JSON.stringify(data) }));
}

document.getElementById('open-promotion')?.addEventListener('click', () => quickForm('Event membership baru', [
    ['name', 'Nama event', 'text'],
    ['discount_percent', 'Diskon (%)', 'number'],
    ['starts_at', 'Tanggal mulai', 'date', [], localDate()],
    ['ends_at', 'Tanggal selesai', 'date', [], localDate()],
    ['members_only', 'Sasaran', 'select', ['1|Khusus member', '0|Semua pelanggan'], '1'],
    ['is_active', 'Status', 'select', ['1|Aktif', '0|Nonaktif'], '1'],
    ['description', 'Catatan (opsional)', 'text'],
], (data) => api('/operasional/promo', { method: 'POST', body: JSON.stringify(data) })));

document.addEventListener('click', async (event) => {
    const memberEdit = event.target.closest('.membership-edit');
    const memberDelete = event.target.closest('.membership-delete');
    const promotionEdit = event.target.closest('.membership-edit-promotion');
    const promotionDelete = event.target.closest('.membership-delete-promotion');

    if (memberEdit) {
        const member = [...array(memberPageState?.data), ...array(state.members)]
            .find((item) => Number(item.id) === Number(memberEdit.dataset.id));
        if (!member) return;
        quickForm(`Edit member: ${member.name}`, [
            ['name', 'Nama pelanggan', 'text', [], member.name],
            ['phone', 'Nomor telepon', 'text', [], member.phone],
            ['email', 'Email (opsional)', 'email', [], member.email],
        ], (data) => api(`/operasional/member/${member.id}`, { method: 'PATCH', body: JSON.stringify(data) }));
    }

    if (memberDelete) {
        const member = [...array(memberPageState?.data), ...array(state.members)]
            .find((item) => Number(item.id) === Number(memberDelete.dataset.id));
        if (!member || !confirm(`Cabut status member untuk ${member.name}? Riwayat pelanggan tetap tersimpan.`)) return;
        try {
            const result = await api(`/operasional/member/${member.id}`, { method: 'DELETE' });
            toast(result.message);
            await refresh();
        } catch (error) {
            toast(error.message, true);
        }
    }

    if (promotionEdit) {
        const promotion = array(state.promotions).find((item) => Number(item.id) === Number(promotionEdit.dataset.id));
        if (!promotion) return;
        quickForm(`Edit event: ${promotion.name}`, [
            ['name', 'Nama event', 'text', [], promotion.name],
            ['discount_percent', 'Diskon (%)', 'number', [], promotion.discount_percent],
            ['starts_at', 'Tanggal mulai', 'date', [], promotion.starts_at],
            ['ends_at', 'Tanggal selesai', 'date', [], promotion.ends_at],
            ['members_only', 'Sasaran', 'select', ['1|Khusus member', '0|Semua pelanggan'], Number(promotion.members_only) ? '1' : '0'],
            ['is_active', 'Status', 'select', ['1|Aktif', '0|Nonaktif'], Number(promotion.is_active) ? '1' : '0'],
            ['description', 'Catatan (opsional)', 'text', [], promotion.description],
        ], (data) => api(`/operasional/promo/${promotion.id}`, { method: 'PATCH', body: JSON.stringify(data) }));
    }

    if (promotionDelete) {
        const promotion = array(state.promotions).find((item) => Number(item.id) === Number(promotionDelete.dataset.id));
        if (!promotion || !confirm(`Hapus event ${promotion.name}? Event akan hilang dari daftar dan tidak bisa dipakai di kasir.`)) return;
        try {
            const result = await api(`/operasional/promo/${promotion.id}`, { method: 'DELETE' });
            toast(result.message);
            await refresh();
        } catch (error) {
            toast(error.message, true);
        }
    }
});

document.getElementById('open-stocktake')?.addEventListener('click', () => {
    renderStocktake();
    openPage('stok-opname');
    requestAnimationFrame(() => document.getElementById('stocktake-search')?.focus());
});
document.getElementById('open-stock-reduction')?.addEventListener('click', openStockReductionForm);
document.getElementById('stocktake-back')?.addEventListener('click', () => openPage('stok'));
document.getElementById('stocktake-reset')?.addEventListener('click', () => {
    stocktakeDraft.clear();
    renderStocktake();
    toast('Isian jumlah masuk dikosongkan.');
});
document.getElementById('stocktake-form')?.addEventListener('submit', submitStocktake);
document.getElementById('stocktake-search')?.addEventListener('input', renderStocktake);
document.getElementById('stocktake-category')?.addEventListener('change', renderStocktake);

document.addEventListener('click', (event) => {
    if (event.target.closest('.go-stock-alerts')) openPage('stok');
});

document.getElementById('treatment-search')?.addEventListener('input', renderTreatments);
document.getElementById('stock-search')?.addEventListener('input', () => {
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(() => loadProductsPage(1).catch((error) => toast(error.message, true)), 250);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelector('.modal.open')?.classList.remove('open');
});

populateSelects();
initReservationControls();
initActivityControls();
resetReservationForm();
renderAll();

// Ambil snapshot terbaru setelah halaman siap agar data operasional tidak
// bergantung pada data awal yang mungkin sudah berubah saat halaman dimuat.
refresh().catch((error) => toast(error.message || 'Data operasional gagal dimuat.', true));
