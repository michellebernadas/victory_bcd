    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/2.2.2/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.2.2/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- jsPDF + AutoTable (used for PDF export on Victory Groups page) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <!-- SheetJS (used for Excel export) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- SortableJS (drag-and-drop) -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <!-- html2canvas — DOM-to-PNG (for exporting non-Chart.js cards like the Discipleship Pipeline) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTables
        if ($('.data-table').length) {
            $('.data-table').DataTable({
                responsive: true,
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
                }
            });
        }

        // Initialize Select2 (form selects)
        if ($('.select2').length) {
            $('.select2').select2({ width: '100%' });
        }

        // Initialize Select2 filter dropdowns (victory groups page)
        if ($('.filter-select2').length) {
            $('.filter-select2').select2({
                width: '100%',
                allowClear: true,
                closeOnSelect: false
            });
            $('.filter-select2').on('select2:select select2:unselect select2:clear', function() {
                if (typeof applyFilters === 'function') applyFilters();
            });
        }

        // Initialize modal Select2 on shown
        $(document).on('shown.bs.modal', '.modal', function() {
            var $modal = $(this);

            // Init any un-initialized modal-select2 inside this modal
            $modal.find('.modal-select2:not(.select2-hidden-accessible)').each(function() {
                var $el = $(this);
                var opts = { dropdownParent: $modal, width: '100%', allowClear: true };
                if ($el.data('tags'))   opts.tags          = true;
                if ($el.attr('multiple')) opts.closeOnSelect = false;
                $el.select2(opts);
            });

            // Populate Edit Group modal Select2 values after init
            if (this.id === 'editGroupModal' && window.pendingEditGroup) {
                var g = window.pendingEditGroup;
                window.pendingEditGroup = null;

                // Type
                $modal.find('#eg_type').val(g.group_type || '').trigger('change.select2');

                // Category (tags — add option if custom)
                var $cat = $modal.find('#eg_category');
                var cat  = g.group_category || '';
                if (cat && !$cat.find('option[value="' + cat + '"]').length) {
                    $cat.append(new Option(cat, cat));
                }
                $cat.val(cat).trigger('change.select2');

                // Day of Week (multi-select, comma-separated stored value)
                var days = (g.day_of_week || '').split(',').map(function(d) { return d.trim(); }).filter(Boolean);
                $modal.find('#eg_day').val(days).trigger('change.select2');

                // Frequency (tags — add option if custom)
                var $freq = $modal.find('#eg_freq');
                var freq  = g.meeting_frequency || '';
                if (freq && !$freq.find('option[value="' + freq + '"]').length) {
                    $freq.append(new Option(freq, freq));
                }
                $freq.val(freq).trigger('change.select2');

                // Location (tags — add option if custom)
                var $loc = $modal.find('#eg_location');
                var loc  = g.location || '';
                if (loc && !$loc.find('option[value="' + loc + '"]').length) {
                    $loc.append(new Option(loc, loc));
                }
                $loc.val(loc).trigger('change.select2');

                // Status
                $modal.find('#eg_status').val(g.group_status || 'active').trigger('change.select2');
            }
        });

        // Auto-dismiss notification alerts
        $('.alert.alert-dismissible:not(.modal .alert):not(.card .alert)').each(function() {
            var alert = $(this);
            setTimeout(function() { alert.fadeOut('slow'); }, 5000);
        });

        // Toggle password visibility
        $('.toggle-password').on('click', function() {
            var input = $($(this).data('target'));
            var icon = $(this).find('i');
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.removeClass('bi-eye').addClass('bi-eye-slash');
            } else {
                input.attr('type', 'password');
                icon.removeClass('bi-eye-slash').addClass('bi-eye');
            }
        });

        // Profile Modal Fix
        $(document).on('click', '[data-bs-target="#profileModal"]', function(e) {
            e.preventDefault();
            $('.modal-backdrop').not('#profileModalBackdrop').remove();
            $('body').removeClass('modal-open');
            $('#profileModalBackdrop').show().addClass('show');
            $('body').addClass('modal-open');
            $('#profileModal').modal('show');
        });

        $('#profileModal').on('show.bs.modal', function() {
            $('.modal-backdrop').not('#profileModalBackdrop').remove();
            $(this).css('z-index', 1060);
        });

        $('#profileModal').on('shown.bs.modal', function() {
            $(this).css('z-index', 1060);
            $('#profileModalBackdrop').css('z-index', 1040);
        });

        $('#profileModal').on('hidden.bs.modal', function() {
            $('#profileModalBackdrop').removeClass('show').hide();
            $('body').removeClass('modal-open');
            $('.modal-backdrop').not('#profileModalBackdrop').remove();
        });

        $(document).on('click', '#profileModalBackdrop', function() {
            $('#profileModal').modal('hide');
        });

        $(document).on('click', '#profileModal .btn-close, #profileModal [data-bs-dismiss="modal"]', function() {
            $('#profileModal').modal('hide');
        });
    });
    </script>

</body>
</html>
