<div class="header-profile" aria-label="Pengguna aktif">
    <span aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
    <div>
        <strong>{{ auth()->user()->name }}</strong>
        <small>{{ auth()->user()->role_name }}</small>
    </div>
</div>
