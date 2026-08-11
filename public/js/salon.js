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
const workStatusTransitions = {
    waiting: ['in_progress', 'cancelled'],
    in_progress: ['continue', 'ready', 'finished', 'overtime', 'cancelled'],
    continue: ['in_progress', 'ready', 'finished', 'overtime', 'cancelled'],
    ready: ['in_progress', 'finished', 'overtime', 'cancelled'],
    overtime: ['continue', 'ready', 'finished', 'cancelled'],
    finished: [],
    cancelled: [],
};

let state = window.SALON_DATA || {};
let selectedReservation = null;
let cashierProductItems = [];
let reservationMode = 'today';
let reservationStatusGroup = null;
let reservationView = 'queue';
let pendingReservationPayload = null;
let paymentIdempotencyKey = null;
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
    calendar.querySelectorAll('.calendar-create-slot').forEach((button) => {
        button.addEventListener('click', () => {
            openReservationForm({
                date: button.dataset.date,
                startTime: button.dataset.time,
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
        return date >= weekStartKey && date <= weekEndKey;
    });

    if (selectedEmployee) {
        rows = rows.filter((reservation) => reservationStaffIds(reservation).includes(selectedEmployee));
    }
    if (reservationStatusGroup === 'arrived') {
        rows = rows.filter((reservation) => ['arrived', 'in_service', 'waiting_payment', 'completed'].includes(reservationStatus(reservation)));
    } else if (selectedStatus) {
        rows = rows.filter((reservation) => reservationStatus(reservation) === selectedStatus);
    }

    const todayRows = all.filter((reservation) => reservationDate(reservation) === selectedDate);
    const short = document.getElementById('queue-short');
    if (short) {
        short.innerHTML = todayRows.slice(0, 5).map((reservation) => {
            const status = reservationStatus(reservation);
            return `<div class="queue-row">
                <strong>${escapeHtml(reservation.queue_number || reservation.booking_code)}</strong>
                <span class="time">${escapeHtml(reservationTime(reservation))}</span>
                <span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTreatmentSummary(reservation))} · ${escapeHtml(reservationStaffSummary(reservation))}</small></span>
                <em class="${statusClass(status)}">${escapeHtml(statusLabel(status))}</em>
                <span class="material-symbols-rounded">chevron_right</span>
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
            return `<div class="calendar-day-head${active}${selectedDay}">${escapeHtml(dayFormat.format(day))}</div>`;
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

        // Reservasi yang waktunya beririsan ditempatkan pada jalur horizontal berbeda.
        const positionedReservations = [];
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
                positionedGroup.forEach((entry) => positionedReservations.push({ ...entry, lanes: laneEnds.length }));
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

        const events = positionedReservations.map(({ reservation, item, itemIndex, timing, day, start, end, lane, lanes }) => {
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
        calendar.innerHTML = `<div class="calendar-grid"><div class="calendar-header"><div class="calendar-corner" aria-hidden="true"></div>${headers}</div><div class="calendar-body"><div class="calendar-time-column">${timeColumn}<span class="calendar-close-time">22.00</span></div>${dayColumns}<div class="calendar-events"><div class="calendar-empty-slots">${createSlots}</div>${events}</div></div></div>`;
        bindReservationCalendarTooltips(calendar, all);
        bindReservationCalendarCreateSlots(calendar);
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
    wrapper.innerHTML = `<div class="modal-box reservation-modal-box">
        <div class="modal-head">
            <div><h2>Detail ${escapeHtml(reservation.queue_number || reservation.booking_code)}</h2><p>${escapeHtml(reservationCustomerName(reservation))} · ${escapeHtml(reservationDate(reservation))}</p></div>
            <button type="button" class="quick-close">×</button>
        </div>
        <div class="quick-info reservation-summary">
            <p><span>Telepon</span><b>${escapeHtml(reservationPhone(reservation) || '-')}</b></p>
            <p><span>Sumber booking</span><b>${escapeHtml(reservation.source || '-')}</b></p>
            <p><span>Status kunjungan</span><b>${escapeHtml(statusLabel(reservationStatus(reservation)))}</b></p>
            <p><span>Catatan</span><b>${escapeHtml(reservation.general_notes || reservation.notes || '-')}</b></p>
        </div>
        <div class="reservation-detail-items">${items.map((item, index) => `<article class="reservation-item-card">
            <div class="reservation-item-title"><strong>${index + 1}. ${escapeHtml(itemTreatmentName(item))}</strong><b>${money(itemPrice(item))}</b></div>
            <div class="reservation-detail-meta">
                <span>Jadwal <b>${escapeHtml(itemStartTime(item, reservation))}</b></span>
                <span>Durasi <b>${Number(item.duration_minutes || 0)} menit</b></span>
                <span>Therapist <b>${escapeHtml(itemStaff(item).map(employeeName).join(', ') || '-')}</b></span>
            </div>
            <label>Status pekerjaan
                <select class="work-status-select status-select" data-reservation-id="${Number(reservation.id)}" data-item-id="${Number(item.id)}" ${reservationStatus(reservation) === 'cancelled' || !(workStatusTransitions[item.work_status] || []).length ? 'disabled' : ''}>
                    ${[item.work_status, ...(workStatusTransitions[item.work_status] || [])].map((value) => `<option value="${value}" ${item.work_status === value ? 'selected' : ''}>${workStatusLabels[value] || value}</option>`).join('')}
                </select>
            </label>
        </article>`).join('') || '<p class="empty-state">Belum ada treatment.</p>'}</div>
        <footer><button type="button" class="primary quick-close">Tutup</button></footer>
    </div>`;
    document.body.appendChild(wrapper);
    wrapper.querySelectorAll('.quick-close').forEach((button) => {
        button.onclick = () => wrapper.remove();
    });
    wrapper.querySelectorAll('.work-status-select').forEach((select) => {
        select.dataset.previous = select.value;
        select.onchange = async () => {
            let reason = null;
            if (select.value === 'cancelled') {
                reason = window.prompt('Tuliskan alasan pembatalan treatment:')?.trim() || '';
                if (!reason) {
                    select.value = select.dataset.previous;
                    toast('Alasan pembatalan wajib diisi.', true);
                    return;
                }
            }
            select.disabled = true;
            try {
                const result = await api(`/operasional/reservasi/${select.dataset.reservationId}/item/${select.dataset.itemId}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ status: select.value, reason }),
                });
                toast(result.message);
                wrapper.remove();
                await refresh();
            } catch (error) {
                select.value = select.dataset.previous;
                select.disabled = false;
                toast(error.message, true);
            }
        };
    });
}

function resetCashier() {
    selectedReservation = null;
    cashierProductItems = [];
    document.getElementById('cashier-receipt')?.classList.add('empty');
    document.getElementById('receipt-number').textContent = '—';
    document.getElementById('receipt-name').textContent = 'Pilih antrean terlebih dahulu';
    document.querySelector('.receipt .member').textContent = '';
    document.getElementById('receipt-items').innerHTML = '<p class="empty-state">Belum ada transaksi yang dipilih.</p>';
    document.getElementById('subtotal').textContent = money(0);
    document.getElementById('discount-value').textContent = money(0);
    document.getElementById('grand-total').textContent = money(0);
    document.getElementById('payment-total').textContent = money(0);
    document.getElementById('payment-description').textContent = 'Pilih transaksi';
    document.getElementById('discount').disabled = true;
    document.getElementById('open-payment').disabled = true;
    document.getElementById('add-extra').disabled = true;
}

function selectedDiscount() {
    return Number(document.getElementById('discount')?.value || 0);
}

function selectedTotal() {
    const reservation = array(state.reservations).find((item) => Number(item.id) === Number(selectedReservation));
    if (!reservation) return 0;
    const serviceSubtotal = reservationSubtotal(reservation);
    const productSubtotal = cashierProductItems.reduce((sum, item) => sum + (Number(item.unit_price) * Number(item.quantity)), 0);
    return Math.round(serviceSubtotal - (serviceSubtotal * selectedDiscount() / 100) + productSubtotal);
}

function renderCashier() {
    const rows = array(state.reservations).filter((reservation) => (
        !isAlreadyPaid(reservation)
        && reservationStatus(reservation) !== 'cancelled'
    ));
    const box = document.getElementById('cashier-queue');
    if (!box) return;

    box.innerHTML = rows.map((reservation, index) => `<button class="cashier-item ${index === 0 ? 'active' : ''}" data-id="${Number(reservation.id)}">
        <strong>${escapeHtml(reservation.queue_number || reservation.booking_code)}</strong>
        <span><b>${escapeHtml(reservationCustomerName(reservation))}</b><small>${escapeHtml(reservationTime(reservation))} · ${escapeHtml(reservationTreatmentSummary(reservation))}</small></span>
        <i class="material-symbols-rounded row-action">chevron_right</i>
    </button>`).join('') || '<p class="empty-state">Belum ada reservasi aktif yang menunggu pembayaran.</p>';

    document.querySelectorAll('.cashier-item').forEach((button) => {
        button.onclick = () => selectCashier(Number(button.dataset.id));
    });

    if (rows.length) {
        const nextId = selectedReservation && rows.some((item) => Number(item.id) === Number(selectedReservation))
            ? selectedReservation
            : rows[0].id;
        selectCashier(Number(nextId));
    } else {
        resetCashier();
    }
}

function selectCashier(id) {
    if (selectedReservation && Number(selectedReservation) !== Number(id)) {
        cashierProductItems = [];
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
    const productSubtotal = cashierProductItems.reduce((sum, item) => sum + (Number(item.unit_price) * Number(item.quantity)), 0);
    const subtotal = serviceSubtotal + productSubtotal;
    const discount = selectedDiscount();
    const discountAmount = Math.round(serviceSubtotal * discount / 100);
    const total = subtotal - discountAmount;

    document.getElementById('cashier-receipt')?.classList.remove('empty');
    document.getElementById('receipt-number').textContent = reservation.queue_number || reservation.booking_code;
    document.getElementById('receipt-name').textContent = reservationCustomerName(reservation);
    document.querySelector('.receipt .member').textContent = reservation.is_member ? '· MEMBER' : '· NON-MEMBER';
    const treatmentLines = items.map((item) => `<div class="receipt-line">
        <i class="material-symbols-rounded">spa</i>
        <span><b>${escapeHtml(itemTreatmentName(item))}</b><small>Therapist: ${escapeHtml(itemStaff(item).map(employeeName).join(', ') || '-')}</small></span>
        <strong>${money(itemPrice(item))}</strong>
    </div>`).join('');
    const productLines = cashierProductItems.map((item) => `<div class="receipt-line receipt-product-line">
        <i class="material-symbols-rounded">inventory_2</i>
        <span><b>${escapeHtml(item.name)}</b><small>${Number(item.quantity)} ${escapeHtml(item.unit || 'pcs')} × ${money(item.unit_price)}</small></span>
        <strong>${money(Number(item.unit_price) * Number(item.quantity))}</strong>
        <button type="button" class="link remove-cashier-product" data-id="${Number(item.product_id)}" aria-label="Hapus produk">×</button>
    </div>`).join('');
    document.getElementById('receipt-items').innerHTML = treatmentLines + productLines;
    document.getElementById('discount').disabled = !reservation.is_member;
    document.getElementById('open-payment').disabled = false;
    document.getElementById('add-extra').disabled = !array(state.products).some((product) => (
        Number(product.is_active ?? 1) === 1 && Number(product.selling_price || 0) > 0 && productStock(product) > 0
    ));
    document.getElementById('subtotal').textContent = money(subtotal);
    document.getElementById('discount-value').textContent = `-${money(discountAmount)}`;
    document.getElementById('grand-total').textContent = money(total);
    document.getElementById('payment-total').textContent = money(total);
    document.getElementById('payment-description').textContent = `${reservation.queue_number || reservation.booking_code} · ${reservationCustomerName(reservation)}`;
}

function openCashierProductPicker() {
    if (!selectedReservation) {
        toast('Pilih antrean terlebih dahulu.', true);
        return;
    }

    const products = array(state.products).filter((product) => (
        Number(product.is_active ?? 1) === 1 && Number(product.selling_price || 0) > 0 && productStock(product) > 0
    ));
    if (!products.length) {
        toast('Tidak ada produk aktif dengan stok dan harga jual yang tersedia.', true);
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><div><h2>Tambah produk</h2><p>Pilih produk dari stok yang tersedia.</p></div><button type="button" class="quick-close">×</button></div><form><div class="quick-fields"><label>Produk<select name="product_id">${products.map((product) => `<option value="${Number(product.id)}">${escapeHtml(product.name)} · ${money(product.selling_price)}</option>`).join('')}</select></label><label>Jumlah<input name="quantity" type="number" min="1" step="0.0001" value="1" required></label><p class="product-picker-stock" id="product-picker-stock"></p></div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary">Tambah</button></footer></form></div>`;
    document.body.appendChild(wrapper);
    const select = wrapper.querySelector('select[name="product_id"]');
    const quantity = wrapper.querySelector('input[name="quantity"]');
    const stockLabel = wrapper.querySelector('#product-picker-stock');
    const syncStock = () => {
        const product = products.find((item) => Number(item.id) === Number(select.value));
        stockLabel.textContent = product ? `Stok tersedia: ${productStock(product)} ${productUnit(product)} · Harga jual: ${money(product.selling_price)}` : '';
        quantity.max = product ? productStock(product) : '';
    };
    syncStock();
    select.onchange = syncStock;
    wrapper.querySelectorAll('.quick-close').forEach((button) => { button.onclick = () => wrapper.remove(); });
    wrapper.querySelector('form').onsubmit = (event) => {
        event.preventDefault();
        const product = products.find((item) => Number(item.id) === Number(select.value));
        const amount = Number(quantity.value || 0);
        if (!product || amount <= 0 || amount > productStock(product)) {
            toast('Jumlah produk melebihi stok yang tersedia.', true);
            return;
        }
        const existing = cashierProductItems.find((item) => Number(item.product_id) === Number(product.id));
        if (existing) {
            if (Number(existing.quantity) + amount > productStock(product)) {
                toast('Jumlah total produk melebihi stok yang tersedia.', true);
                return;
            }
            existing.quantity = Number(existing.quantity) + amount;
        } else {
            cashierProductItems.push({ product_id: Number(product.id), name: product.name, unit: productUnit(product), unit_price: Number(product.selling_price), quantity: amount });
        }
        wrapper.remove();
        selectCashier(selectedReservation);
        toast('Produk ditambahkan ke transaksi.');
    };
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
        <p><span><i class="material-symbols-rounded">schedule</i>${Number(treatment.duration_minutes)} menit</span><span><i class="material-symbols-rounded">percent</i>Komisi ${Number(treatment.default_commission_percent ?? treatment.commission_percent ?? 0)}%</span></p>
        <div class="treatment-foot"><span><small>Harga normal</small><b>${money(treatmentPrice(treatment))}</b></span><span class="treatment-actions">${recipeCount ? `<button type="button" class="recipe-info-button" data-id="${Number(treatment.id)}" title="Lihat ${recipeCount} produk dalam resep" aria-label="Lihat ${recipeCount} produk dalam resep ${escapeHtml(treatment.name)}"></button>` : ''}<button type="button" class="recipe-button" data-id="${Number(treatment.id)}">Atur resep</button></span></div>
    </article>`;
    }).join('') || '<p class="empty-state">Belum ada treatment.</p>';

    document.querySelectorAll('.recipe-button').forEach((button) => {
        const treatment = treatments.find((item) => Number(item.id) === Number(button.dataset.id));
        button.onclick = () => openRecipeChecklist(treatment);
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
        <div class="modal-head"><div><h2>Resep produk</h2><p>${escapeHtml(treatment.name)}</p></div><button type="button" class="quick-close" aria-label="Tutup">×</button></div>
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
        <div class="modal-head"><div><h2>Atur resep produk</h2><p>${escapeHtml(treatment.name)} · centang setiap produk yang dipakai.</p></div><button type="button" class="quick-close">×</button></div>
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
    const members = array(state.members);
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
            <i class="material-symbols-rounded row-action">chevron_right</i>
        </div>`).join('') || '<p class="empty-state">Belum ada member.</p>';
    }

    if (events) {
        events.innerHTML = array(state.promotions).map((promotion, index) => `<div class="event ${index ? 'pale' : ''}">
            <small>AKTIF</small><h3>${escapeHtml(promotion.name)}</h3>
            <p>Diskon ${Number(promotion.discount_percent)}%${promotion.members_only ? ' khusus member' : ''}</p>
            <span>${new Date(`${promotion.starts_at}T00:00:00`).toLocaleDateString('id-ID')}–${new Date(`${promotion.ends_at}T00:00:00`).toLocaleDateString('id-ID')}</span>
        </div>`).join('') || '<p class="empty-state">Belum ada event membership aktif.</p>';
    }
}

function renderStock() {
    const products = array(state.products);
    const movements = array(state.stock_movements);
    const box = document.getElementById('stock-list');
    const history = document.getElementById('stock-history');
    const count = document.getElementById('product-count');
    if (count) count.textContent = products.length;

    if (box) {
        box.innerHTML = products.length ? `<div class="tr th"><span>PRODUK</span><span>STOK TERSEDIA</span><span>MINIMUM</span><span>PERKIRAAN</span><span>STATUS</span><span>AKSI</span></div>${products.map((product) => {
            const stock = productStock(product);
            const minimum = productMinimum(product);
            const unit = productUnit(product);
            return `<div class="tr">
                <span><b>${escapeHtml(product.name)}</b><small>${escapeHtml(product.category || '-')}</small></span>
                <span><b>${stock} ${escapeHtml(unit)}</b></span><span>${minimum} ${escapeHtml(unit)}</span>
                <span><div class="progress"><i style="width:${Math.min(100, stock / Math.max(1, minimum) * 50)}%"></i></div></span>
                <em class="pill">${stock <= minimum ? 'Menipis' : 'Aman'}</em>
                <button class="link stock-edit" data-id="${Number(product.id)}">Ubah stok</button>
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
    set('finance-transaction-count', Number(dashboard.month_transaction_count || 0));
    set('finance-transaction-average', `Rata-rata ${money(dashboard.month_transaction_average)}`);

    const flow = document.getElementById('cash-bars');
    const maximum = Math.max(income, expense, 1);
    if (flow) {
        flow.innerHTML = income || expense ? `<div><span>Pemasukan</span><i style="width:${income / maximum * 100}%"></i><b>${money(income)}</b></div><div><span>Pengeluaran</span><i style="width:${expense / maximum * 100}%"></i><b>${money(expense)}</b></div>` : '<p class="empty-state">Belum ada arus kas bulan ini.</p>';
    }

    const today = localDate();
    const transactions = array(state.transactions).filter((transaction) => String(transaction.created_at || transaction.transacted_at).slice(0, 10) === today);
    const box = document.getElementById('transactions');
    if (box) {
        box.innerHTML = transactions.map((transaction) => {
            const paymentNames = array(transaction.payments).map((payment) => payment.payment_method_name || payment.payment_method?.name).filter(Boolean);
            return `<div class="transaction"><i class="material-symbols-rounded">receipt_long</i><span><b>${escapeHtml(transaction.customer_name || transaction.customer?.name || 'Pelanggan')}</b><small>${escapeHtml(transaction.number)} · ${escapeHtml(paymentNames.join(' + ') || transaction.payment_method || '-')}</small></span><strong>${money(transaction.total)}</strong></div>`;
        }).join('') || '<p class="empty-state">Belum ada transaksi hari ini.</p>';
    }
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
        <i class="material-symbols-rounded">work_history</i>
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
        const points = revenue.map((item, index) => ({
            px: 4 + (index * (93 / Math.max(1, revenue.length - 1))),
            py: 92 - (Number(item.total || 0) / scale * 80),
            total: Number(item.total || 0),
        }));
        chart.innerHTML = `<span class="axis a1">${compactMoney(maximum)}</span><span class="axis a2">${compactMoney(maximum / 2)}</span><span class="axis a3">Rp0</span><div class="chart-grid"></div><div class="chart-line"><svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="Grafik pendapatan tujuh hari"><polyline points="${points.map((point) => `${point.px},${point.py}`).join(' ')}"></polyline></svg>${points.map((point) => `<i style="--x:${point.px}%;--y:${point.py}%" title="${money(point.total)}"></i>`).join('')}</div><div class="chart-labels">${revenue.map((item) => `<span title="${escapeHtml(item.date)}">${escapeHtml(item.label)}</span>`).join('')}</div>`;
    }

    const treatments = array(dashboard.treatment_last_7_days);
    const performance = document.getElementById('treatment-performance');
    if (performance) {
        const maximum = Math.max(0, ...treatments.map((item) => Number(item.total || 0)));
        performance.innerHTML = treatments.length ? treatments.map((item) => `<div><span>${escapeHtml(item.name)}</span><i><b style="width:${maximum ? Number(item.total) / maximum * 100 : 0}%"></b></i><strong>${Number(item.total).toLocaleString('id-ID')}</strong></div>`).join('') : '<p class="empty-state">Belum ada treatment yang dibayar dalam 7 hari terakhir.</p>';
    }

    const availability = document.getElementById('therapist-availability');
    if (availability) {
        const today = localDate();
        const activeReservations = array(state.reservations).filter((reservation) => (
            reservationDate(reservation) === today
            && !['cancelled', 'completed'].includes(reservationStatus(reservation))
        ));
        availability.innerHTML = serviceProviders().map((employee) => {
            const reservation = activeReservations.find((item) => reservationStaffIds(item).includes(Number(employee.id)));
            const initials = String(employee.name || '').split(' ').map((part) => part[0]).slice(0, 2).join('');
            return `<div><i>${escapeHtml(initials)}</i><span><b>${escapeHtml(employee.name)}</b><small>${escapeHtml(employee.specialty || employee.position || 'Therapist')}</small></span><em class="${reservation ? 'busy' : ''}">${reservation ? `Melayani ${escapeHtml(reservation.queue_number || reservation.booking_code)}` : 'Tersedia'}</em></div>`;
        }).join('') || '<p class="empty-state">Belum ada therapist.</p>';
        if (low) {
            availability.insertAdjacentHTML('beforeend', `<div class="stock-mini"><i>!</i><span><b>Peringatan stok menipis</b><small>${low} produk di bawah stok minimum</small></span><button class="link">Lihat detail</button></div>`);
        }
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
        <button type="button" class="icon-button remove-staff" aria-label="Hapus therapist">×</button>`;
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
    card.innerHTML = `<div class="reservation-item-title"><strong>Treatment ${itemNumber}</strong><button type="button" class="link remove-reservation-item">Hapus</button></div>
        <div class="reservation-item-grid">
            <label>Treatment<select class="item-treatment" required><option value="">Pilih treatment</option>${treatmentOptions(values.treatment_id)}</select></label>
            <label class="time-field">Jam mulai (24 jam)<select class="item-time" required>${reservationTimeOptions(values.start_time || '09:00')}</select><small>Slot setiap 30 menit</small></label>
            ${capabilities.override_price ? `<label>Harga aktual<input class="item-price" type="number" min="0" step="1" placeholder="Harga normal" value="${escapeHtml(values.actual_price || '')}"></label>` : '<span class="reservation-price-note"><small>Harga</small><b>Mengikuti harga normal</b></span>'}
        </div>
        <label class="item-notes">Catatan treatment<textarea class="item-note" placeholder="Opsional">${escapeHtml(values.notes || '')}</textarea></label>
        <div class="staff-block"><div class="staff-block-head"><span>Pembagian therapist</span><button type="button" class="link add-staff">＋ Tambah therapist</button></div><div class="staff-rows"></div></div>`;
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

    modal('reservation-modal');
    requestAnimationFrame(() => document.querySelector('#reservation-form [name="name"]')?.focus());
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

    return {
        name: formData.get('name'),
        phone: formData.get('phone'),
        date: formData.get('date'),
        source: formData.get('source'),
        notes: formData.get('notes') || null,
        items,
    };
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
        await refresh();
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

function addPaymentRow(values = {}) {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    const methods = paymentMethods();
    const row = document.createElement('div');
    row.className = 'payment-row';
    row.innerHTML = `<label>Metode<select class="payment-method" required>${methods.map((method) => `<option value="${Number(method.id)}" ${Number(method.id) === Number(values.payment_method_id) ? 'selected' : ''}>${escapeHtml(method.name)}</option>`).join('')}</select></label>
        <label>Jumlah<input class="payment-amount" type="number" min="1" step="1" required value="${Number(values.amount || 0)}"></label>
        <label class="payment-reference-label">Referensi<input class="payment-reference" placeholder="Opsional"></label>
        <button type="button" class="icon-button remove-payment" aria-label="Hapus pembayaran">×</button>`;
    container.appendChild(row);
    row.querySelector('.payment-amount').addEventListener('input', updatePaymentReconciliation);
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
    const required = Number(method?.requires_reference ?? 0) === 1;
    input.required = required;
    input.placeholder = required ? 'Wajib diisi' : 'Opsional';
    if (label) label.firstChild.textContent = required ? 'Referensi *' : 'Referensi';
}

function resetPaymentRows() {
    const container = document.getElementById('payment-rows');
    if (!container) return;
    container.innerHTML = '';
    addPaymentRow({ amount: selectedTotal() });
    paymentIdempotencyKey = newIdempotencyKey();
}

function updatePaymentReconciliation() {
    const total = selectedTotal();
    const entered = [...document.querySelectorAll('.payment-amount')]
        .reduce((sum, input) => sum + Number(input.value || 0), 0);
    const difference = total - entered;
    const missingReference = [...document.querySelectorAll('.payment-row')].some((row) => {
        const method = paymentMethods().find((item) => Number(item.id) === Number(row.querySelector('.payment-method').value));
        return Number(method?.requires_reference ?? 0) === 1 && !row.querySelector('.payment-reference').value.trim();
    });
    const panel = document.querySelector('.payment-reconciliation');
    document.getElementById('payment-entered').textContent = money(entered);
    document.getElementById('payment-difference').textContent = money(difference);
    panel?.classList.toggle('has-difference', difference !== 0);
    const button = document.getElementById('complete-payment');
    if (button) button.disabled = difference !== 0 || entered <= 0 || !paymentMethods().length || missingReference;
}

function quickForm(title, fields, submit) {
    const wrapper = document.createElement('div');
    wrapper.className = 'modal open quick-modal';
    wrapper.innerHTML = `<div class="modal-box small"><div class="modal-head"><h2>${escapeHtml(title)}</h2><button type="button" class="quick-close">×</button></div><form><div class="quick-fields">${fields.map(([name, label, type, options, value]) => `<label>${escapeHtml(label)}${type === 'select' ? `<select name="${escapeHtml(name)}">${array(options).map((option) => {
        const parts = String(option).split('|');
        const optionValue = parts.length > 1 ? parts[0] : option;
        return `<option value="${escapeHtml(optionValue)}" ${String(optionValue) === String(value ?? '') ? 'selected' : ''}>${escapeHtml(parts[1] || parts[0])}</option>`;
    }).join('')}</select>` : `<input name="${escapeHtml(name)}" type="${escapeHtml(type)}" value="${escapeHtml(value ?? '')}" required>`}</label>`).join('')}</div><footer><button type="button" class="secondary quick-close">Batal</button><button class="primary">Simpan</button></footer></form></div>`;
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
}

function populateSelects() {
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
    setReservationView(reservationView);
    document.querySelectorAll('[data-reservation-view]').forEach((tab) => {
        tab.addEventListener('click', () => {
            setReservationView(tab.dataset.reservationView);
        });
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
document.getElementById('open-payment')?.addEventListener('click', () => {
    if (!selectedReservation) {
        toast('Pilih antrean terlebih dahulu.', true);
        return;
    }
    resetPaymentRows();
    modal('payment-modal');
});
document.querySelectorAll('.close-modal').forEach((button) => {
    button.onclick = () => closeModal(button);
});

document.querySelectorAll('.stock-tab').forEach((button) => {
    button.onclick = () => {
        document.querySelectorAll('.stock-tab').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        document.getElementById('stock-list').classList.toggle('hidden', button.dataset.stock !== 'list');
        document.getElementById('stock-history').classList.toggle('hidden', button.dataset.stock !== 'history');
    };
});

document.getElementById('discount')?.addEventListener('change', () => {
    if (selectedReservation) selectCashier(selectedReservation);
});
document.getElementById('add-extra')?.addEventListener('click', openCashierProductPicker);
document.addEventListener('click', (event) => {
    const button = event.target.closest('.remove-cashier-product');
    if (!button) return;
    cashierProductItems = cashierProductItems.filter((item) => Number(item.product_id) !== Number(button.dataset.id));
    if (selectedReservation) selectCashier(selectedReservation);
});

document.getElementById('reservation-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideConflictPanel();
    const payload = collectReservationPayload(event.currentTarget);
    await submitReservation(payload);
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

document.getElementById('add-payment-row')?.addEventListener('click', () => addPaymentRow());
document.getElementById('complete-payment')?.addEventListener('click', async () => {
    const button = document.getElementById('complete-payment');
    const payments = [...document.querySelectorAll('.payment-row')].map((row) => ({
        payment_method_id: Number(row.querySelector('.payment-method').value),
        amount: Number(row.querySelector('.payment-amount').value),
        reference_number: row.querySelector('.payment-reference').value.trim() || null,
    }));
    const invalidReference = [...document.querySelectorAll('.payment-row')].find((row) => {
        const method = paymentMethods().find((item) => Number(item.id) === Number(row.querySelector('.payment-method').value));
        return Number(method?.requires_reference ?? 0) === 1 && !row.querySelector('.payment-reference').value.trim();
    });
    if (invalidReference) {
        invalidReference.querySelector('.payment-reference').focus();
        toast('Nomor referensi wajib untuk metode pembayaran tersebut.', true);
        return;
    }
    button.disabled = true;
    try {
        const result = await api('/operasional/pembayaran', {
            method: 'POST',
            body: JSON.stringify({
                reservation_id: selectedReservation,
                discount_percent: String(selectedDiscount()),
                product_items: cashierProductItems.map((item) => ({
                    product_id: Number(item.product_id),
                    quantity: String(item.quantity),
                })),
                payments,
                idempotency_key: paymentIdempotencyKey,
            }),
        });
        document.getElementById('payment-modal').classList.remove('open');
        toast(`${result.message}: ${result.number || result.transaction_number || ''}`.trim());
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
    ], (data) => api('/operasional/member', { method: 'POST', body: JSON.stringify(data) }));
}

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
    if (event.target.closest('.stock-mini .link')) openPage('stok');
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
