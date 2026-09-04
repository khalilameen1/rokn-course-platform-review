<nav class="admin-actions mb-4" aria-label="عمليات الدفع">
    <a class="btn {{ isRouteActive('admin.orders.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.orders.index') }}">
        <i class="fa fa-shopping-cart"></i> العمليات
    </a>
    <a class="btn {{ isRouteActive('admin.payment-reconciliation-findings.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.payment-reconciliation-findings.index') }}">
        <i class="fa fa-balance-scale"></i> مراجعة التسويات
    </a>
    <a class="btn {{ isRouteActive('admin.packages.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.packages.index') }}">
        <i class="fa fa-cubes"></i> باقات العملات
    </a>
</nav>
