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
const canViewSales = Boolean(capabilities.view_sales);
const canRefundSales = Boolean(capabilities.refund_sales);
const canViewMemberships = Boolean(capabilities.view_memberships);
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
    waiting: 'Menunggu',
    in_progress: 'Mulai dikerjakan',
    continue: 'Dilanjutkan',
    ready: 'Siap diselesaikan',
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
let salesPageState = null;
let salesSearchTimer;
let memberPageState = null;
let memberSearchTimer;
let selectedReservation = null;
let reservationMode = 'today';
let reservationStatusGroup = null;
let reservationView = 'queue';
let calendarMode = 'week';
let pendingReservationPayload = null;
let paymentIdempotencyKey = null;
let paymentMode = null;
let selectedPaymentMethodId = null;
let toastTimer;
let reservationCalendarTooltipTimer;
let reservationCalendarTooltipListenersBound = false;
let reservationCalendarTooltipAnchor = null;

const copy = {
    dashboard: ['Dashboard', 'Ringkasan operasional salon hari ini'],
    reservasi: ['Reservasi', 'Kelola jadwal dan antrean pelanggan'],
    pegawai: ['Pegawai', 'Kelola master pegawai dan therapist'],
    kasir: ['Kasir', 'Proses pelayanan, diskon member, dan pembayaran'],
    treatment: ['Treatment', 'Kelola menu, paket, harga, dan resep produk'],
    membership: ['Membership', 'Data member dan program khusus'],
    stok: ['Produk & Stok', 'Pantau persediaan dan pergerakan produk'],
    penjualan: ['Penjualan', 'Riwayat transaksi lunas dan cetak ulang nota'],
    keuangan: ['Keuangan', 'Arus kas dan laporan transaksi'],
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
    const response = await fetch(url, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
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
    state = await api('/operasional/data');
    populateSelects();
    renderAll();
    if (canViewSales) await loadSalesPage(salesPageState?.meta?.current_page || 1);
    if (canViewMemberships) await loadMembersPage(memberPageState?.meta?.current_page || 1);
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
    const workStatus = workStatusLabels[item?.work_status] || item?.work_status || '-';
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
        <div><dt>Status pekerjaan</dt><dd>${escapeHtml(workStatus)}</dd></div>
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
    const nav = document.querySelector(`#navigation [data-page="${id}"]`);
    const page = document.getElementById(id);
    if (!nav || !page) return;

    document.querySelectorAll('.page').forEach((element) => element.classList.remove('active'));
    page.classList.add('active');
    document.querySelectorAll('#navigation [data-page]').forEach((element) => {
        element.classList.toggle('active', element.dataset.page === id);
    });
    document.getElementById('page-title').textContent = copy[id][0];
    document.getElementById('page-subtitle').textContent = copy[id][1];
    history.replaceState(null, '', `#${id}`);
    scrollTo(0, 0);
}

function openDashboardMetric(card) {
    const target = card.dataset.target;
    if (!target) return;
    openPage(target);

    if (target === 'reservasi') {
        const section = document.getElementById('reservasi');
        const filters = section?.querySelectorAll('.filters input,.filters select');
        const tabs = section?.querySelectorAll('.tabs button');
        const requestedStatus = card.dataset.reservationStatus || '';
        reservationMode = 'today';
        reservationStatusGroup = requestedStatus === 'arrived' ? 'arrived' : null;
        tabs?.forEach((tab, index) => tab.classList.toggle('active', index === 0));
        if (filters?.[0]) filters[0].value = localDate();
        if (filters?.[2]) filters[2].value = reservationStatusGroup ? '' : requestedStatus;
        renderReservations();
    }

    if (target === 'stok') {
        document.querySelector('.stock-tab[data-stock="list"]')?.click();
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
    const section = document.getElementById('reservasi');
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
                <strong>${escapeHtml(reservation.queue_number || reservation.booking_code)}</strong>
                <span class="time">${escapeHtml(reservationTime(reservation))}</span>
                <span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTreatmentSummary(reservation))} · ${escapeHtml(reservationStaffSummary(reservation))}</small></span>
                <em class="${statusClass(status)}">${escapeHtml(statusLabel(status))}</em>
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
            const dailyHeaders = dailyTherapists.map((employee, index) => `<div class="therapist-day-head" style="grid-column:${index + 2}"><b>${escapeHtml(employee.name)}</b><small>${escapeHtml(employee.specialty || 'Therapist')}</small></div>`).join('');
            const dailyTimes = slots.map((slot) => {
                const minutes = openingMinutes + (slot * 30);
                const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                return `<div class="therapist-day-time" style="grid-column:1;grid-row:${slot + 2}">${slot % 2 === 0 ? time.replace(':', '.') : ''}</div>`;
            }).join('');
            const dailySlots = canCreateReservations ? dailyTherapists.flatMap((employee, index) => slots.map((slot) => {
                const minutes = openingMinutes + (slot * 30);
                const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                return `<button type="button" class="therapist-create-slot" data-date="${selectedDate}" data-time="${time}" data-employee-id="${Number(employee.id)}" aria-label="Tambah reservasi ${escapeHtml(employee.name)}, ${time}" style="grid-column:${index + 2};grid-row:${slot + 2}"></button>`;
            })).join('') : '';
            const dailyEvents = dayRows.flatMap(({ reservation, item, itemIndex, timing, start, end }) => {
                const staff = itemStaff(item);
                return staff.map((assignment) => {
                    const employeeId = Number(assignment.employee_id ?? assignment.employee?.id ?? assignment.id);
                    const therapistIndex = dailyTherapists.findIndex((employee) => Number(employee.id) === employeeId);
                    if (therapistIndex < 0) return '';
                    const status = reservationCalendarStatus(reservation);
                    const startRow = Math.max(2, Math.floor((start - openingMinutes) / 30) + 2);
                    const span = Math.max(1, Math.ceil((end - start) / 30));
                    const staffName = employeeName(assignment);
                    const ariaLabel = `${timing.startLabel} sampai ${timing.endLabel}, ${reservationCustomerName(reservation)}, ${itemTreatmentName(item)}, therapist ${staffName}`;
                    return `<button type="button" class="calendar-event therapist-day-event ${statusClass(status)} status-${escapeHtml(status)} reservation-detail" data-id="${Number(reservation.id)}" data-item-index="${itemIndex}" aria-label="${escapeHtml(ariaLabel)}" style="grid-column:${therapistIndex + 2};grid-row:${startRow} / span ${span}"><span class="calendar-event-main"><time>${escapeHtml(timing.startLabel)}</time><b>${escapeHtml(reservationCustomerName(reservation))}</b></span><small>${escapeHtml(itemTreatmentName(item))}</small></button>`;
                });
            }).join('');
            const empty = dailyTherapists.length ? '' : '<p class="empty-state therapist-day-empty">Belum ada therapist aktif untuk ditampilkan.</p>';
            calendar.setAttribute('aria-label', 'Kalender harian per therapist');
            calendar.innerHTML = `<div class="calendar-day-view-head"><div><b>${escapeHtml(new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(selected))}</b><small>Satu kolom adalah satu therapist. Klik <strong>+</strong> pada slot kosong untuk membuat reservasi dengan therapist dan jam sudah terisi.</small></div><button type="button" class="secondary calendar-week-back">← Ringkasan mingguan</button></div>${empty}<div class="therapist-day-calendar" style="--therapist-count:${Math.max(1, dailyTherapists.length)}"><div class="therapist-day-corner">Jam</div>${dailyHeaders}${dailyTimes}${dailySlots}${dailyEvents}</div>`;
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
            const currentStatus = item.work_status || 'waiting';
            const nextStatus = currentStatus === 'waiting'
                ? 'in_progress'
                : ['in_progress', 'continue', 'ready', 'overtime'].includes(currentStatus)
                    ? 'finished'
                    : null;
            const actionLabel = nextStatus === 'in_progress' ? 'Mulai treatment' : 'Selesaikan treatment';
            const actionIcon = nextStatus === 'in_progress' ? 'play_arrow' : 'check';
            const action = nextStatus && canUpdateReservations
                ? `<button type="button" class="treatment-progress-action ${nextStatus === 'finished' ? 'primary' : ''}" data-item-id="${Number(item.id)}" data-next-status="${nextStatus}"><span class="material-symbols-outlined">${actionIcon}</span>${actionLabel}</button>`
                : '';

            return `<article class="reservation-item-card">
                <div class="reservation-item-title"><strong>${index + 1}. ${escapeHtml(itemTreatmentName(item))}</strong><b>${money(itemPrice(item))}</b></div>
                <div class="reservation-detail-meta">
                    <span>Jadwal <b>${escapeHtml(itemStartTime(item, reservation))}</b></span>
                    <span>Durasi <b>${Number(item.duration_minutes || 0)} menit</b></span>
                    <span>Therapist <b>${escapeHtml(itemStaff(item).map(employeeName).join(', ') || '-')}</b></span>
                </div>
                <div class="reservation-work-status"><span><small>Status pengerjaan</small><b class="status-${escapeHtml(currentStatus)}">${escapeHtml(workStatusLabels[currentStatus] || currentStatus)}</b></span>${action}</div>
            </article>`;
        }).join('') || '<p class="empty-state">Belum ada treatment.</p>'}</div>
        <footer><button type="button" class="primary quick-close">Tutup</button></footer>
    </div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelectorAll('.treatment-progress-action').forEach((button) => {
        button.onclick = async () => {
            const nextStatus = button.dataset.nextStatus;
            const isFinishing = nextStatus === 'finished';
            if (isFinishing && !await confirmAction({
                title: 'Selesaikan treatment?',
                message: 'Status pengerjaan akan ditandai selesai dan tersimpan di riwayat reservasi.',
                confirmLabel: 'Ya, selesaikan',
            })) return;

            button.disabled = true;
            try {
                await api(`/operasional/reservasi/${Number(reservation.id)}/item/${Number(button.dataset.itemId)}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ status: nextStatus }),
                });
                wrapper.remove();
                await refresh();
                toast(isFinishing ? 'Treatment ditandai selesai.' : 'Treatment dimulai.');
            } catch (error) {
                toast(error.message, true);
                button.disabled = false;
            }
        };
    });
}

function resetCashier() {
    selectedReservation = null;
    const receipt = document.getElementById('cashier-receipt');
    receipt?.classList.add('empty');
    if (receipt) receipt.hidden = true;
    document.querySelector('#kasir .cashier-grid')?.classList.add('cashier-awaiting-selection');
    document.getElementById('receipt-number').textContent = '—';
    document.getElementById('receipt-name').textContent = 'Pilih antrean terlebih dahulu';
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
    document.getElementById('open-payment').disabled = true;
    document.getElementById('add-extra').disabled = true;
    resetPaymentRows();
}

function selectedDiscount() {
    return Number(document.getElementById('discount')?.value || 0);
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
    </button>`).join('') || '<p class="empty-state">Belum ada reservasi aktif yang menunggu pembayaran.</p>';

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
    document.getElementById('discount').disabled = !reservation.is_member;
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
        number: result.number || result.transaction_number,
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
            return {
                name: method?.name || 'Pembayaran',
                isCash: Boolean(Number(method?.is_cash ?? 0)),
                amount: Number(payment.amount),
                tenderedAmount: Number(payment.tendered_amount || payment.amount),
                reference: payment.reference_number,
            };
        }),
        subtotal,
        discount,
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

    const itemRows = receipt.items.map((item) => `<div class="receipt-item">
        <div><b>${escapeHtml(item.name)}</b><span>${escapeHtml(String(item.quantity))} x ${money(item.unitPrice)}</span></div>
        <b class="receipt-item-total">${money(item.total)}</b>
    </div>`).join('');
    const cashPayments = receipt.payments.filter((payment) => payment.isCash);
    const nonCashPayments = receipt.payments.filter((payment) => !payment.isCash);
    const paymentRows = [
        ...cashPayments.map((payment) => `<p><span>Tunai</span><i>:</i><b>${money(payment.tenderedAmount)}</b></p>`),
        ...nonCashPayments.map((payment) => `<p><span>${escapeHtml(payment.name)}</span><i>:</i><b>${money(payment.amount)}</b></p>`),
        ...(receipt.change > 0 ? [`<p><span>Kembali</span><i>:</i><b>${money(receipt.change)}</b></p>`] : []),
    ].join('');
    const totals = [
        `<p><span>Subtotal</span><i>:</i><b>${money(receipt.subtotal)}</b></p>`,
        ...(receipt.discount ? [`<p><span>Diskon member</span><i>:</i><b>-${money(receipt.discount)}</b></p>`] : []),
        `<p><span>Total</span><i>:</i><b>${money(receipt.total)}</b></p>`,
        `<p class="grand-total"><span>Grand Total</span><i>:</i><b>${money(receipt.total)}</b></p>`,
    ].join('');
    const logoUrl = `${window.location.origin}/images/selesa-logo.png`;
    const pageSize = compact ? '58mm auto' : 'A4';
    const sheetClass = compact ? 'thermal' : 'nota';

    documentWindow.document.write(`<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>${escapeHtml(receipt.number)}</title>
<style>
@page{size:${pageSize};margin:${compact ? '2mm' : '16mm'}}
*{box-sizing:border-box}
body{margin:0;color:#202020;background:#fff;font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.28}
.sheet{margin:0 auto}.sheet.thermal{width:54mm;max-width:100%}.sheet.nota{width:150mm;max-width:100%;font-size:13px}
.receipt-header{text-align:center}.receipt-header img{display:block;width:${compact ? '31mm' : '56mm'};height:auto;margin:0 auto 3px;filter:grayscale(1) contrast(1.25)}
.brand-name{margin:0;font-size:${compact ? '16px' : '21px'};font-weight:700;letter-spacing:.15px}.brand-subtitle{margin:0;font-size:${compact ? '8.5px' : '11px'};font-weight:700;letter-spacing:0}.receipt-code{margin-top:2px;font-size:${compact ? '10px' : '12px'};font-weight:700}
.dash{border-top:1px dashed #4d4d4d;margin:7px 0 6px}.dash.thin{margin:6px 0}
.receipt-meta{margin:0;font-size:11px}.receipt-meta p{display:grid;grid-template-columns:max-content 3mm minmax(0,1fr);gap:1px;margin:1px 0}.receipt-meta span{text-align:right}.receipt-meta b{text-align:right;font-weight:700;white-space:nowrap}.receipt-meta i{font-style:normal;text-align:center}.receipt-totals p,.receipt-payments p{display:grid;grid-template-columns:1fr 3mm auto;gap:1px;margin:1px 0}.receipt-totals span,.receipt-payments span{text-align:right}.receipt-totals b,.receipt-payments b{text-align:right;font-weight:700}.receipt-totals i,.receipt-payments i{font-style:normal;text-align:center}
.reprint{text-align:center;font-size:${compact ? '10px' : '15px'};font-weight:700;margin:0}
.receipt-item{display:grid;grid-template-columns:1fr auto;gap:4px;padding:1px 0}.receipt-item b{display:block}.receipt-item span{display:block;padding-left:2mm;margin-top:0}.receipt-item-total{align-self:end;white-space:nowrap}
.receipt-totals{margin-top:0}.receipt-totals p{margin:0;padding:2px 0;border-bottom:1px dashed #4d4d4d}.receipt-totals .grand-total{font-size:${compact ? '13px' : '16px'};margin-top:2px;font-weight:700}.receipt-payments p{margin:2px 0}.receipt-footer{text-align:center;margin-top:7px}.receipt-footer strong{display:block;font-size:${compact ? '11px' : '15px'};margin-bottom:5px}.receipt-footer p{margin:1px 0;text-align:left;padding-left:${compact ? '2mm' : '15mm'}}
@media print{body{background:#fff}}
</style></head><body>
<main class="sheet ${sheetClass}">
    <header class="receipt-header">
        <img src="${escapeHtml(logoUrl)}" alt="Logo Selesa">
        <p class="brand-name">selesa</p>
        <p class="brand-subtitle">SALON. SPA. WELLNESS. NAIL. EYELASH</p>
        <p class="receipt-code">${escapeHtml(receipt.number)}</p>
    </header>
    <div class="dash"></div>
    <section class="receipt-meta">
        <p><span>Pelanggan</span><i>:</i><b>${escapeHtml(receipt.customer)}</b></p>
        <p><span>Transaksi</span><i>:</i><b>${escapeHtml(receipt.date)}</b></p>
        <p><span>Karyawan</span><i>:</i><b>${escapeHtml(receipt.cashier)}</b></p>
    </section>
    <div class="dash thin"></div>
    <p class="reprint">Cetak Ulang</p>
    <div class="dash thin"></div>
    <section class="receipt-items">${itemRows}</section>
    <div class="dash thin"></div>
    <section class="receipt-totals">${totals}</section>
    <section class="receipt-payments">${paymentRows}</section>
    <div class="dash thin"></div>
    <footer class="receipt-footer"><strong>TERIMA KASIH</strong><p>Whatsapp&nbsp;&nbsp;: 081128702019</p><p>Instagram&nbsp;: @selesa.salonspa</p></footer>
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
    wrapper.innerHTML = showSuccessAnimation
        ? `<div class="modal-box transaction-success-modal" role="status" style="position:relative;width:min(390px,calc(100vw - 32px));min-height:410px;overflow:hidden;border:0;border-radius:22px;background:#f2f1ee;"><button type="button" class="quick-close transaction-success-close" aria-label="Tutup" style="position:absolute;z-index:1;top:13px;right:13px;width:32px;height:32px;border:0;border-radius:50%;background:transparent;cursor:pointer;"><span class="material-symbols-outlined">close</span></button><div class="transaction-success-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:295px;padding:52px 24px 30px;text-align:center;"><span class="transaction-success-emblem" aria-hidden="true" style="display:grid;place-items:center;width:90px;height:90px;margin-bottom:23px;background:#62c52f;clip-path:polygon(50% 0%,61% 9%,76% 6%,83% 20%,97% 25%,92% 40%,100% 50%,92% 60%,97% 75%,83% 80%,76% 94%,61% 91%,50% 100%,39% 91%,24% 94%,17% 80%,3% 75%,8% 60%,0 50%,8% 40%,3% 25%,17% 20%,24% 6%,39% 9%);"><svg viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" style="width:61px;height:61px;"><path d="M17 33l10 10 21-22"/></svg></span><h2 style="margin:0;color:#171513;font-size:21px;letter-spacing:.02em;">TRANSAKSI BERHASIL</h2><p style="margin:10px 0 0;color:#69635e;font-size:12px;font-weight:650;">${escapeHtml(description)}</p><div class="transaction-success-meta" style="display:grid;gap:4px;margin-top:12px;color:#827b75;font-size:9px;font-weight:600;line-height:1.35;">${transactionMeta}</div></div><div class="transaction-success-actions" style="display:grid;grid-template-columns:1fr 1fr;gap:13px;padding:0 26px 30px;"><button type="button" class="success-print-button" data-print="struk" style="min-height:55px;border:0;border-radius:17px;background:#765039;color:#fff;font:700 11px/1 inherit;letter-spacing:.02em;text-transform:uppercase;cursor:pointer;">Cetak struk</button><button type="button" class="success-print-button" data-print="nota" style="min-height:55px;border:0;border-radius:17px;background:#765039;color:#fff;font:700 11px/1 inherit;letter-spacing:.02em;text-transform:uppercase;cursor:pointer;">Cetak nota</button></div></div>`
        : `<div class="modal-box small"><div class="modal-head"><div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(description)}</p></div><button type="button" class="quick-close"><span class="material-symbols-outlined">close</span></button></div>${printChoices}</div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelectorAll('[data-print]').forEach((button) => {
        button.onclick = () => printReceipt(receipt, button.dataset.print);
    });

}

function renderTreatments() {
    const treatments = array(state.treatments);
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
            quickForm(`Ubah komisi: ${treatment.name}`, [
                ['default_commission_percent', 'Komisi treatment (%)', 'number', [], Number(treatment.default_commission_percent ?? 0)],
            ], (data) => api(`/operasional/treatment/${Number(treatment.id)}/komisi`, {
                method: 'PATCH',
                body: JSON.stringify(data),
            }));
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
            <div class="recipe-checklist">${products.map((product) => {
                const recipe = recipes.get(Number(product.id));
                const checked = Boolean(recipe);
                const quantity = recipe ? String(Number(recipe.quantity)) : '';
                return `<label class="recipe-product${checked ? ' selected' : ''}">
                    <input class="recipe-product-toggle" type="checkbox" data-id="${Number(product.id)}" ${checked ? 'checked' : ''}>
                    <span class="recipe-product-info"><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.category || 'Produk')} · stok ${escapeHtml(productStock(product))} ${escapeHtml(productUnit(product))}</small></span>
                    <span class="recipe-product-quantity"><input class="recipe-product-quantity-input" type="number" min="0.0001" step="0.0001" inputmode="decimal" placeholder="Jumlah" value="${escapeHtml(quantity)}" ${checked ? 'required' : 'disabled'}><small>${escapeHtml(productUnit(product))}</small></span>
                </label>`;
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
    const products = array(state.products);
    const movements = array(state.stock_movements);
    const box = document.getElementById('stock-list');
    const history = document.getElementById('stock-history');
    const count = document.getElementById('product-count');
    if (count) count.textContent = products.length;

    if (box) {
        box.innerHTML = products.length ? `<div class="tr th"><span>PRODUK</span><span>STOK TERSEDIA</span><span>MINIMUM</span><span>HARGA JUAL</span><span>PERKIRAAN</span><span>STATUS</span><span>AKSI</span></div>${products.map((product) => {
            const stock = productStock(product);
            const minimum = productMinimum(product);
            const unit = productUnit(product);
            return `<div class="tr">
                <span><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.category || '-')}</small></span>
                <span><b>${stock} ${escapeHtml(unit)}</b></span><span>${minimum} ${escapeHtml(unit)}</span>
                <span class="product-price"><b>${money(product.selling_price)}</b></span>
                <span><div class="progress"><i style="width:${Math.min(100, stock / Math.max(1, minimum) * 50)}%"></i></div></span>
                <em class="pill">${stock <= minimum ? 'Menipis' : 'Aman'}</em>
                <span class="product-row-actions"><button type="button" class="secondary product-edit" data-id="${Number(product.id)}"><span class="material-symbols-outlined">edit</span> Edit</button><button type="button" class="link stock-edit" data-id="${Number(product.id)}">Stok</button></span>
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
        </div>`).join('')}` : '<p class="empty-state">Belum ada riwayat stok.</p>';
    }

    document.querySelectorAll('.stock-edit').forEach((button) => {
        button.onclick = () => quickForm('Ubah stok', [
            ['type', 'Jenis', 'select', ['masuk', 'keluar', 'opname']],
            ['quantity', 'Jumlah', 'number'],
            ['source', 'Sumber / catatan', 'text'],
        ], (data) => api(`/operasional/produk/${button.dataset.id}/stok`, {
            method: 'PATCH',
            body: JSON.stringify(data),
        }));
    });

    document.querySelectorAll('.product-edit').forEach((button) => {
        const product = products.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!product) return;
        button.onclick = () => openProductEdit(product);
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
    form.querySelector('[name="is_active"]').value = Number(product.is_active ?? 1) ? '1' : '0';
    form.querySelector('[name="description"]').value = product.description || '';
    const title = document.getElementById('product-edit-title');
    if (title) title.textContent = `Edit produk: ${product.name}`;
    modal('product-edit-modal');
    requestAnimationFrame(() => form.querySelector('[name="name"]')?.focus());
}

function renderFinance() {
    const dashboard = state.dashboard || {};
    const set = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };
    const income = Number(dashboard.month_income || 0);
    const expense = Number(dashboard.month_expense || 0);

    set('finance-income', money(income));
    set('finance-expense', money(expense));
    set('finance-balance', money(dashboard.month_balance));
    set('finance-period', new Date().toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }));
    set('finance-cash-entry-count', Number(dashboard.month_cash_entry_count || 0));
    set('finance-cash-entry-note', 'Bulan berjalan');

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
            </div>` : '<p class="empty-state">Belum ada arus kas bulan ini.</p>';
    }

    const today = localDate();
    const transactions = array(state.transactions).filter((transaction) => String(transaction.created_at || transaction.transacted_at).slice(0, 10) === today);
    const box = document.getElementById('transactions');
    if (box) {
        box.innerHTML = transactions.map((transaction) => {
            const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name || payment.payment_method?.name).filter(Boolean);
            return `<div class="transaction"><i class="material-symbols-outlined">receipt_long</i><span><b>${escapeHtml(transaction.customer_name || transaction.customer?.name || 'Pelanggan')}</b><small>${escapeHtml(transaction.number)} · ${escapeHtml(paymentNames.join(' + ') || transaction.payment_method || '-')}</small></span><strong>${money(transaction.total)}</strong></div>`;
        }).join('') || '<p class="empty-state">Belum ada transaksi hari ini.</p>';
    }

    const categoryBox = document.getElementById('finance-category-bars');
    if (categoryBox) {
        const categories = array(dashboard.month_expense_categories);
        const categoryMaximum = Math.max(1, ...categories.map((item) => Number(item.total || 0)));
        categoryBox.innerHTML = categories.length
            ? categories.map((item) => `<div class="cash-flow-row"><div class="cash-flow-head"><span>${escapeHtml(item.category)}</span><b>${money(item.total)}</b></div><div class="cash-flow-track"><i class="cash-flow-fill expense" style="width:${Number(item.total || 0) / categoryMaximum * 100}%"></i></div></div>`).join('')
            : '<p class="empty-state">Belum ada pengeluaran kas pada bulan ini.</p>';
    }

    renderCashEntryHistory();
}

function transactionReceiptPayload(transaction) {
    const transactedAt = transaction.transacted_at || transaction.created_at;
    const transactedDate = transactedAt ? new Date(String(transactedAt).replace(' ', 'T')) : null;
    const date = transactedDate && !Number.isNaN(transactedDate.getTime())
        ? transactedDate.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
        : '-';

    return {
        transactionId: Number(transaction.id),
        number: transaction.number,
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
            tenderedAmount: Number(payment.tendered_amount || payment.amount || 0),
            reference: payment.reference_number,
        })),
        subtotal: Number(transaction.subtotal || 0),
        discount: Number(transaction.discount_amount || 0),
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
            const haystack = [transaction.number, transaction.customer_name, ...paymentNames]
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
            <span><b>${escapeHtml(transaction.number)}</b><small>${escapeHtml(formatTransactionDate(transaction.transacted_at || transaction.created_at))}</small></span>
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
            description: `${transaction.number} · ${money(transaction.total)}`,
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
    salesPageState = await api(`/operasional/penjualan?${params.toString()}`);
    renderSales();
}

function renderSales() {
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
        const refundableProducts = array(transaction.items).filter((item) => item.item_type === 'product' && Number(item.refundable_quantity || 0) > 0);
        const returnReceipts = array(transaction.returns).map((salesReturn) => `<button type="button" class="sales-return-receipt" data-return-id="${Number(salesReturn.id)}" title="Cetak ${escapeHtml(salesReturn.number)}"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span>${escapeHtml(salesReturn.number)}</button>`).join('');
        return `<div class="tr sales-row"><span><b>${escapeHtml(transaction.number)}</b><small>${escapeHtml(formatTransactionDate(transaction.transacted_at || transaction.created_at))}</small></span><span><b>${escapeHtml(transaction.customer_name || 'Pelanggan')}</b><small>${transaction.is_member ? 'Member' : 'Pelanggan umum'}</small></span><span><b>${escapeHtml(itemSummary)}</b><small>${itemNames.length} item${refundedAmount > 0 ? ` · <em class="sales-return-status">diretur ${money(refundedAmount)}</em>` : ''}</small></span><span><em class="sales-payment">${escapeHtml(paymentNames)}</em></span><span class="sales-net-total"><b>${money(transaction.net_total ?? transaction.total)}</b>${refundedAmount > 0 ? `<small>Awal ${money(transaction.total)}</small>` : ''}</span><div class="sales-actions"><button type="button" class="sales-reprint-button" data-id="${Number(transaction.id)}"><span class="material-symbols-outlined" aria-hidden="true">print</span> Nota</button>${canRefundSales && refundableProducts.length ? `<button type="button" class="sales-return-button" data-id="${Number(transaction.id)}"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span> Retur</button>` : ''}${returnReceipts}</div></div>`;
    }).join('');
    box.innerHTML = `<div class="tr th"><span>INVOICE & TANGGAL</span><span>PELANGGAN</span><span>RINCIAN</span><span>PEMBAYARAN</span><span class="align-right">TOTAL</span><span>AKSI</span></div>${rows || '<p class="empty-state">Belum ada transaksi lunas yang sesuai.</p>'}`;

    box.querySelectorAll('.sales-reprint-button').forEach((button) => {
        const transaction = transactions.find((item) => Number(item.id) === Number(button.dataset.id));
        if (!transaction) return;
        button.onclick = () => openReceiptPrintChoice(transactionReceiptPayload(transaction), {
            title: 'Cetak ulang nota',
            description: `${transaction.number} \u00b7 ${money(transaction.total)}`,
        });
    });
    box.querySelectorAll('.sales-return-button').forEach((button) => {
        const transaction = transactions.find((item) => Number(item.id) === Number(button.dataset.id));
        if (transaction) button.onclick = () => openSalesReturn(transaction);
    });
    box.querySelectorAll('.sales-return-receipt').forEach((button) => {
        button.onclick = () => window.open(`/operasional/retur/${Number(button.dataset.returnId)}/struk.pdf`, '_blank', 'noopener');
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
        <div class="modal-head sales-return-head"><div><span class="sales-return-kicker">Retur produk</span><h2>${escapeHtml(transaction.number)}</h2><p>${escapeHtml(transaction.customer_name || 'Pelanggan')} · Pilih produk dan jumlah yang dikembalikan.</p></div><button type="button" class="quick-close" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div>
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

function renderCashEntryHistory() {
    const box = document.getElementById('cash-entry-history');
    if (!box) return;

    const typeFilter = document.getElementById('cash-entry-type-filter');
    const search = document.getElementById('cash-entry-search');
    const from = document.getElementById('cash-entry-from')?.value || '';
    const to = document.getElementById('cash-entry-to')?.value || '';
    const type = typeFilter?.value || '';
    const keyword = String(search?.value || '').trim().toLocaleLowerCase('id-ID');
    const entries = array(state.cash_entries).filter((entry) => {
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
            <span><small class="finance-source ${automated ? 'automatic' : 'manual'}">${automated ? `Otomatis${entry.transaction_number ? ` · ${escapeHtml(entry.transaction_number)}` : ''}` : 'Manual'}</small></span>
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

function renderActivity() {
    const box = document.getElementById('activity-list');
    if (!box) return;
    const activities = array(state.activities);
    const dateFilter = document.getElementById('activity-filter-date');
    const userFilter = document.getElementById('activity-filter-user');
    const actionFilter = document.getElementById('activity-filter-action');
    const users = [...new Set(activities.map((activity) => activity.user_name || 'Sistem'))].sort((a, b) => a.localeCompare(b, 'id'));
    const actions = [...new Set(activities.map((activity) => activity.action).filter(Boolean))].sort();

    if (userFilter) {
        const selected = userFilter.value;
        userFilter.innerHTML = `<option value="">Semua pengguna</option>${users.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('')}`;
        userFilter.value = users.includes(selected) ? selected : '';
    }
    if (actionFilter) {
        const selected = actionFilter.value;
        actionFilter.innerHTML = `<option value="">Semua jenis aktivitas</option>${actions.map((action) => `<option value="${escapeHtml(action)}">${escapeHtml(action.replaceAll('.', ' · '))}</option>`).join('')}`;
        actionFilter.value = actions.includes(selected) ? selected : '';
    }

    const rows = activities.filter((activity) => (
        (!dateFilter?.value || String(activity.created_at || '').slice(0, 10) === dateFilter.value)
        && (!userFilter?.value || (activity.user_name || 'Sistem') === userFilter.value)
        && (!actionFilter?.value || activity.action === actionFilter.value)
    ));

    box.innerHTML = rows.map((activity) => `<div class="activity">
        <time>${new Date(activity.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</time>
        <i class="material-symbols-outlined">work_history</i>
        <span><b>${escapeHtml(activity.description)}</b><p>${escapeHtml(activity.action)}</p><small>${escapeHtml(activity.user_name || activity.user?.name || 'Sistem')}</small></span>
    </div>`).join('') || '<p class="empty-state">Tidak ada aktivitas yang sesuai filter.</p>';
}

function compactMoney(value) {
    const number = Number(value || 0);
    if (number >= 1000000000) return `Rp${(number / 1000000000).toLocaleString('id-ID', { maximumFractionDigits: 1 })}M`;
    if (number >= 1000000) return `Rp${(number / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 })}jt`;
    if (number >= 1000) return `Rp${(number / 1000).toLocaleString('id-ID', { maximumFractionDigits: 0 })}rb`;
    return money(number);
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
    const paymentCategoryMeta = {
        cash: { name: 'TUNAI', icon: 'payments', order: 1 },
        bank_transfer: { name: 'BANK', icon: 'account_balance', order: 2 },
        card: { name: 'CC', icon: 'credit_card', order: 3 },
        qris: { name: 'QRIS', icon: 'qr_code_2', order: 4 },
    };
    const paymentCategories = [...paymentMethodsRevenue.reduce((groups, payment) => {
        const key = Boolean(payment.is_cash) ? 'cash' : payment.type;
        const meta = paymentCategoryMeta[key] || { name: String(key || 'LAINNYA').toUpperCase(), icon: 'payments', order: 99 };
        const existing = groups.get(key) || { key, ...meta, total: 0, activeCount: 0 };
        existing.total += Number(payment.total || 0);
        existing.activeCount += payment.is_active === false ? 0 : 1;
        groups.set(key, existing);
        return groups;
    }, new Map()).values()].sort((left, right) => left.order - right.order || left.name.localeCompare(right.name));
    set('payment-revenue-total', money(totalPaymentRevenue?.total || 0));
    set('payment-revenue-note', `${paymentCategories.length} kategori pembayaran`);
    if (paymentRevenueList) {
        const total = Number(totalPaymentRevenue?.total || 0);
        paymentRevenueList.innerHTML = paymentCategories.length ? paymentCategories.map((payment) => {
            const amount = payment.total;
            const percent = total > 0 ? Math.round((amount / total) * 100) : 0;
            return `<article class="payment-revenue-item">
                <div class="payment-method-label"><i class="material-symbols-outlined" aria-hidden="true">${payment.icon}</i><span><b>${escapeHtml(payment.name)}</b><small>${payment.activeCount ? `${percent}% dari pendapatan hari ini` : 'Kategori nonaktif · riwayat tetap tercatat'}</small></span></div>
                <strong>${money(amount)}</strong>
                <div class="payment-revenue-track" aria-label="${percent}% dari pendapatan"><i style="width:${percent}%"></i></div>
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

    const revenue = array(dashboard.revenue_last_7_days);
    const chart = document.getElementById('revenue-chart');
    if (chart) {
        const maximum = Math.max(0, ...revenue.map((item) => Number(item.total || 0)));
        const scale = maximum || 1;
        const weeklyTotal = revenue.reduce((sum, item) => sum + Number(item.total || 0), 0);
        const average = revenue.length ? Math.round(weeklyTotal / revenue.length) : 0;
        const points = revenue.map((item, index) => ({
            px: 3 + (index * (94 / Math.max(1, revenue.length - 1))),
            py: 92 - (Number(item.total || 0) / scale * 76),
            total: Number(item.total || 0),
            label: item.label,
            date: item.date,
        }));
        const line = points.map((point) => `${point.px},${point.py}`).join(' ');
        const area = `${points[0]?.px ?? 0},100 ${line} ${points.at(-1)?.px ?? 100},100`;
        chart.innerHTML = `<div class="revenue-chart-summary"><span><small>Total 7 hari</small><strong>${money(weeklyTotal)}</strong></span><span><small>Rata-rata / hari</small><strong>${money(average)}</strong></span></div><div class="revenue-line-canvas"><div class="revenue-line-plot"><span class="axis a1">${compactMoney(maximum)}</span><span class="axis a2">${compactMoney(maximum / 2)}</span><span class="axis a3">Rp0</span><div class="chart-grid"></div><svg class="revenue-line-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="Grafik pendapatan tujuh hari"><defs><linearGradient id="revenue-area-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#a87559" stop-opacity=".20"></stop><stop offset="100%" stop-color="#a87559" stop-opacity=".015"></stop></linearGradient></defs><polygon points="${area}"></polygon><polyline points="${line}"></polyline></svg>${points.map((point) => `<button type="button" class="revenue-line-point" style="--x:${point.px}%;--y:${point.py}%" aria-label="Pendapatan ${escapeHtml(point.label)}: ${money(point.total)}" data-date="${escapeHtml(point.date)}" data-total="${money(point.total)}"></button>`).join('')}<div class="revenue-line-tooltip" role="status" aria-live="polite"><small></small><strong></strong></div></div><div class="chart-labels">${revenue.map((item) => `<span title="${escapeHtml(item.date)}">${escapeHtml(item.label)}</span>`).join('')}</div></div>`;

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

    const treatmentStockAlerts = document.getElementById('treatment-stock-alerts');
    if (treatmentStockAlerts) {
        const alerts = array(dashboard.treatment_stock_alerts);
        treatmentStockAlerts.innerHTML = alerts.length
            ? alerts.map((alert) => `<div class="treatment-stock-alert"><i class="material-symbols-outlined" aria-hidden="true">warning</i><span><b>${escapeHtml(alert.treatment_name)}</b><small>${escapeHtml(alert.product_name)} · tersisa ${Number(alert.current_stock || 0).toLocaleString('id-ID')} ${escapeHtml(alert.unit || '')}</small></span><em>Menipis</em></div>`).join('')
            : '<p class="empty-state">Semua bahan resep aman. Menu treatment siap dijual.</p>';
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

function addStaffRow(container, role = 'primary') {
    const row = document.createElement('div');
    row.className = 'staff-row';
    row.innerHTML = `<label>Therapist<select class="item-employee" required><option value="">Pilih therapist</option>${employeeOptions()}</select></label>
        <label>Peran<select class="item-staff-role"><option value="primary" ${role === 'primary' ? 'selected' : ''}>Utama</option><option value="assistant" ${role === 'assistant' ? 'selected' : ''}>Pendamping</option></select></label>
        <button type="button" class="icon-button remove-staff" aria-label="Hapus therapist"><span class="material-symbols-outlined">close</span></button>`;
    container.appendChild(row);
    row.querySelector('.remove-staff').onclick = () => {
        if (container.children.length <= 1) {
            toast('Setiap treatment minimal memiliki satu therapist.', true);
            return;
        }
        row.remove();
    };
}

function reservationTimeOptions(selected = '09:00') {
    selected = String(selected).slice(0, 5);
    return Array.from({ length: 26 }, (_, index) => {
        const totalMinutes = (9 * 60) + (index * 30);
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
            <label>Treatment<select class="item-treatment" required><option value="">Pilih treatment</option>${treatmentOptions(values.treatment_id)}</select></label>
            <label class="time-field">Jam mulai (24 jam)<select class="item-time" required>${reservationTimeOptions(values.start_time || '09:00')}</select><small>Slot setiap 30 menit</small></label>
            ${capabilities.override_price ? `<label>Harga aktual<input class="item-price" type="number" min="0" step="1" placeholder="Harga normal" value="${escapeHtml(values.actual_price || '')}"></label>` : '<span class="reservation-price-note"><small>Harga</small><b>Mengikuti harga normal</b></span>'}
        </div>
        <label class="item-notes">Catatan treatment<textarea class="item-note" placeholder="Opsional">${escapeHtml(values.notes || '')}</textarea></label>
        <div class="staff-block"><div class="staff-block-head"><span>Pembagian therapist</span><button type="button" class="link add-staff"><span class="material-symbols-outlined">add</span> Tambah therapist</button></div><div class="staff-rows"></div></div>`;
    container.appendChild(card);

    const staffContainer = card.querySelector('.staff-rows');
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
        const priceInput = card.querySelector('.item-price');
        if (treatment && priceInput && !priceInput.value) priceInput.placeholder = String(treatmentPrice(treatment));
    };
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

function openReservationForm(values = {}) {
    hideReservationCalendarTooltip();
    resetReservationForm();

    const date = document.getElementById('reservation-date');
    if (date && values.date) date.value = values.date;

    const startTime = document.querySelector('#reservation-items .item-time');
    if (startTime && values.startTime) startTime.value = values.startTime;

    const therapist = document.querySelector('#reservation-items .item-employee');
    if (therapist && values.employeeId && [...therapist.options].some((option) => Number(option.value) === Number(values.employeeId))) {
        therapist.value = String(values.employeeId);
    }

    modal('reservation-modal');
    requestAnimationFrame(() => document.querySelector('#reservation-form [name="name"]')?.focus());
}

function syncReservationCustomerType() {
    const form = document.getElementById('reservation-form');
    const type = form?.querySelector('[name="customer_type"]:checked')?.value || 'guest';
    const picker = document.getElementById('reservation-member-picker');
    const select = document.getElementById('reservation-member-id');
    const preview = document.getElementById('reservation-member-preview');
    const name = form?.querySelector('[name="name"]');
    const phone = form?.querySelector('[name="phone"]');
    const isMember = type === 'member';

    if (picker) picker.hidden = !isMember;
    [name, phone].filter(Boolean).forEach((field) => {
        field.disabled = isMember;
        field.required = !isMember;
    });
    if (select) {
        const selectedMemberId = select.value;
        select.required = isMember;
        select.innerHTML = `<option value="">Pilih member</option>${array(state.members).map((member) => `<option value="${Number(member.id)}">${escapeHtml(member.name)} · ${escapeHtml(member.phone || '-')}</option>`).join('')}`;
        select.value = isMember && [...select.options].some((option) => option.value === selectedMemberId)
            ? selectedMemberId
            : '';
    }

    const member = array(state.members).find((item) => Number(item.id) === Number(select?.value));
    if (preview) preview.textContent = member
        ? `${member.name} · ${member.phone || 'tanpa nomor telepon'} · Member sejak ${member.member_since ? new Date(`${member.member_since}T00:00:00`).toLocaleDateString('id-ID') : '-'}`
        : 'Pilih member untuk memakai data pelanggan yang sudah terdaftar.';
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
    if (submit) submit.disabled = true;
    try {
        const result = await api('/operasional/reservasi', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        document.getElementById('reservation-modal')?.classList.remove('open');
        resetReservationForm();
        toast(result.message || 'Reservasi berhasil disimpan.');
        if (!upsertReservation(result.reservation)) {
            await refresh();
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
    const sourceSelect = `<label>${sourceLabel} *<select class="payment-source-select" aria-label="${sourceLabel}">${option.methods.map((item) => `<option value="${Number(item.id)}" ${Number(item.id) === Number(method.id) ? 'selected' : ''}>${escapeHtml(item.code)} | ${escapeHtml(item.name)}</option>`).join('')}</select></label>`;

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

function addPaymentRow(values = {}) {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    const methods = paymentMethods();
    const selectedMethod = methods.find((method) => Number(method.id) === Number(values.payment_method_id));
    const row = document.createElement('div');
    row.className = `payment-row${values.fixed_method ? ' fixed-method' : ''}${values.fixed_method && selectedMethod?.type === 'card' ? ' has-card-details' : ''}`;
    row.dataset.autoBalance = values.auto_balance ? 'true' : 'false';
    row.dataset.autoTendered = 'true';
    row.innerHTML = `<label>Metode<select class="payment-method" required>${methods.map((method) => `<option value="${Number(method.id)}" ${Number(method.id) === Number(values.payment_method_id) ? 'selected' : ''}>${escapeHtml(method.name)}</option>`).join('')}</select></label>
        <label>Nominal pembayaran<input class="payment-amount" type="number" min="1" step="1" required value="${Number(values.amount || 0)}"></label>
        <label class="payment-tendered-label" hidden>Uang diterima<input class="payment-tendered" type="number" min="1" step="1" value="${Number(values.tendered_amount || values.amount || 0)}"></label>
        <label class="payment-reference-label">Referensi<input class="payment-reference" placeholder="Opsional"></label>
        <button type="button" class="icon-button remove-payment" aria-label="Hapus pembayaran"><span class="material-symbols-outlined">close</span></button>`;
    container.appendChild(row);
    row.querySelector('.payment-amount').addEventListener('input', () => {
        row.dataset.autoBalance = 'false';
        syncCashTendered(row);
        syncSplitAutoBalance();
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-tendered').addEventListener('input', () => {
        row.dataset.autoTendered = 'false';
        updatePaymentReconciliation();
    });
    row.querySelector('.payment-method').disabled = Boolean(values.fixed_method);
    row.querySelector('.payment-method').addEventListener('change', () => {
        syncPaymentReference(row);
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
    syncPaymentReference(row);
    updatePaymentReconciliation();
}

function syncPaymentReference(row) {
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
    const entered = [...document.querySelectorAll('.payment-amount')]
        .reduce((sum, input) => sum + Number(input.value || 0), 0);
    const cashRows = [...document.querySelectorAll('.payment-row.is-cash')];
    const cashTendered = cashRows.reduce((sum, row) => sum + Number(row.querySelector('.payment-tendered')?.value || 0), 0);
    const cashAllocated = cashRows.reduce((sum, row) => sum + Number(row.querySelector('.payment-amount')?.value || 0), 0);
    const change = Math.max(0, cashTendered - cashAllocated);
    const invalidCashTender = cashRows.some((row) => Number(row.querySelector('.payment-tendered')?.value || 0) < Number(row.querySelector('.payment-amount')?.value || 0));
    const difference = total - entered;
    const panel = document.querySelector('.payment-reconciliation');
    document.getElementById('payment-entered').textContent = money(entered);
    document.getElementById('payment-difference').textContent = money(difference);
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
    if (memberSelect) {
        const selected = memberSelect.value;
        memberSelect.innerHTML = `<option value="">Pilih member</option>${array(state.members).map((member) => `<option value="${Number(member.id)}">${escapeHtml(member.name)} · ${escapeHtml(member.phone || '-')}</option>`).join('')}`;
        memberSelect.value = [...memberSelect.options].some((option) => option.value === selected) ? selected : '';
        syncReservationCustomerType();
    }

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

function initReservationControls() {
    const section = document.getElementById('reservasi');
    const date = document.getElementById('reservation-calendar-date');
    const employee = document.getElementById('reservation-filter-employee');
    const status = document.getElementById('reservation-filter-status');
    if (date) date.value = localDate();
    [date, employee, status].filter(Boolean).forEach((filter) => filter.addEventListener('change', () => {
        reservationStatusGroup = null;
        renderReservations();
    }));

    const moveWeek = (direction) => {
        if (!date?.value) return;
        const selected = new Date(`${date.value}T12:00:00`);
        selected.setDate(selected.getDate() + (direction * 7));
        date.value = `${selected.getFullYear()}-${String(selected.getMonth() + 1).padStart(2, '0')}-${String(selected.getDate()).padStart(2, '0')}`;
        reservationStatusGroup = null;
        renderReservations();
    };
    document.getElementById('calendar-prev')?.addEventListener('click', () => moveWeek(-1));
    document.getElementById('calendar-next')?.addEventListener('click', () => moveWeek(1));
    document.getElementById('calendar-today')?.addEventListener('click', () => {
        if (!date) return;
        date.value = localDate();
        reservationStatusGroup = null;
        renderReservations();
    });

    const setReservationView = (view) => {
        reservationView = view;
        document.querySelectorAll('[data-reservation-view]').forEach((item) => {
            const active = item.dataset.reservationView === reservationView;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.getElementById('reservation-queue-view')?.classList.toggle('hidden', reservationView !== 'queue');
        document.getElementById('reservation-calendar-view')?.classList.toggle('hidden', reservationView !== 'calendar');
        section?.querySelector('.calendar-controls')?.classList.toggle('hidden', reservationView !== 'calendar');
    };
    const setCalendarMode = (mode) => {
        calendarMode = mode === 'day' ? 'day' : 'week';
        document.querySelectorAll('[data-calendar-mode]').forEach((item) => {
            const active = item.dataset.calendarMode === calendarMode;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        renderReservations();
    };
    setReservationView(reservationView);
    document.querySelectorAll('[data-reservation-view]').forEach((tab) => {
        tab.addEventListener('click', () => {
            setReservationView(tab.dataset.reservationView);
        });
    });
    document.querySelectorAll('[data-calendar-mode]').forEach((tab) => {
        tab.addEventListener('click', () => setCalendarMode(tab.dataset.calendarMode));
    });
}

function initActivityControls() {
    ['activity-filter-date', 'activity-filter-user', 'activity-filter-action'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', renderActivity);
    });
}

document.querySelectorAll('#navigation [data-page]').forEach((button) => {
    button.onclick = () => openPage(button.dataset.page);
});
document.querySelectorAll('.go-reservation').forEach((button) => {
    button.onclick = () => openPage('reservasi');
});
document.querySelectorAll('.go-stock').forEach((button) => {
    button.onclick = () => {
        openPage('stok');
        document.querySelector('.stock-tab[data-stock="list"]')?.click();
    };
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
document.getElementById('add-reservation-item')?.addEventListener('click', () => addReservationItem());
document.getElementById('open-product')?.addEventListener('click', () => modal('product-modal'));
document.getElementById('open-cash-entry')?.addEventListener('click', openCashEntryForm);
document.getElementById('open-payroll')?.addEventListener('click', openPayrollForm);
document.getElementById('sales-search')?.addEventListener('input', () => {
    clearTimeout(salesSearchTimer);
    salesSearchTimer = setTimeout(() => loadSalesPage(1).catch((error) => toast(error.message, true)), 250);
});
document.getElementById('sales-payment-filter')?.addEventListener('change', () => loadSalesPage(1).catch((error) => toast(error.message, true)));
document.getElementById('member-search')?.addEventListener('input', () => {
    clearTimeout(memberSearchTimer);
    memberSearchTimer = setTimeout(() => loadMembersPage(1).catch((error) => toast(error.message, true)), 250);
});
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

document.querySelectorAll('.stock-tab').forEach((button) => {
    button.onclick = () => {
        document.querySelectorAll('.stock-tab').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        const showingHistory = button.dataset.stock === 'history';
        document.getElementById('stock-list').hidden = showingHistory;
        document.getElementById('stock-history').hidden = !showingHistory;
        document.getElementById('stock-list-actions').hidden = showingHistory;
        document.getElementById('stock-history-actions').hidden = !showingHistory;
    };
});

document.getElementById('export-schedule')?.addEventListener('click', () => {
    const date = document.getElementById('reservation-calendar-date')?.value || localDate();
    window.location.assign(`/operasional/reservasi/ekspor?date=${encodeURIComponent(date)}`);
});

document.getElementById('export-stock-history')?.addEventListener('click', () => {
    const today = localDate();
    const from = `${today.slice(0, 8)}01`;
    window.location.assign(`/operasional/produk/riwayat-ekspor?from=${encodeURIComponent(from)}&to=${encodeURIComponent(today)}`);
});

document.getElementById('discount')?.addEventListener('change', () => {
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
    const payments = [...document.querySelectorAll('.payment-row')].map((row) => ({
        payment_method_id: Number(row.querySelector('.payment-method').value),
        amount: Number(row.querySelector('.payment-amount').value),
        tendered_amount: row.classList.contains('is-cash')
            ? Number(row.querySelector('.payment-tendered').value)
            : Number(row.querySelector('.payment-amount').value),
        reference_number: cardReference || row.querySelector('.payment-reference').value.trim() || null,
        notes: cardNumber ? `Nomor kartu: ${cardNumber}` : null,
    }));
    button.disabled = true;
    try {
        const reservation = array(state.reservations).find((item) => Number(item.id) === Number(selectedReservation));
        const receipt = receiptPayload({ total: selectedTotal() }, reservation, reservationProductItems(reservation), payments);
        const result = await api('/operasional/pembayaran', {
            method: 'POST',
            body: JSON.stringify({
                reservation_id: selectedReservation,
                discount_percent: String(selectedDiscount()),
                payments,
                idempotency_key: paymentIdempotencyKey,
            }),
        });
        toast(`${result.message}: ${result.number || result.transaction_number || ''}`.trim());
        receipt.number = result.number || result.transaction_number || receipt.number;
        receipt.transactionId = Number(result.id || receipt.transactionId || 0) || null;
        receipt.total = Number(result.total || receipt.total);
        receipt.change = Number(result.change_amount || receipt.change || 0);
        receipt.cashier = result.cashier_name || receipt.cashier;
        openReceiptPrintChoice(receipt);
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

document.getElementById('open-stocktake')?.addEventListener('click', () => quickForm('Stok opname', [
    ['product_id', 'Produk', 'select', array(state.products).map((product) => `${product.id}|${product.name}`)],
    ['quantity', 'Stok aktual', 'number'],
    ['source', 'Catatan', 'text'],
], (data) => {
    const id = data.product_id.split('|')[0];
    delete data.product_id;
    data.type = 'opname';
    return api(`/operasional/produk/${id}/stok`, { method: 'PATCH', body: JSON.stringify(data) });
}));

document.addEventListener('click', (event) => {
    if (event.target.closest('.go-stock-alerts')) openPage('stok');
});

document.querySelector('.search input')?.addEventListener('input', (event) => {
    const query = event.target.value.toLowerCase();
    document.querySelectorAll('.queue-row,.member-row,.treatment-card,.stock-table .tr:not(.th),.activity').forEach((element) => {
        element.style.display = element.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
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
