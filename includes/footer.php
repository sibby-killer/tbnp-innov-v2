<?php
// Get current year for copyright
$currentYear = date('Y');
?>
<footer class="footer mt-auto py-3 bg-light border-top">
    <div class="container">
        <div class="row">
            <div class="col-md-6 text-center text-md-start">
                <span class="text-muted">
                    &copy; <?php echo $currentYear; ?> <?php echo SITE_NAME; ?>. All rights reserved.
                </span>
            </div>
            <div class="col-md-6 text-center text-md-end">
                <span class="text-muted">
                    Version 1.0.0 | 
                    <a href="#" class="text-decoration-none text-muted" data-bs-toggle="modal" data-bs-target="#aboutModal">
                        <i class="fas fa-info-circle"></i> About
                    </a>
                </span>
            </div>
        </div>
    </div>
</footer>

<!-- About Modal -->
<div class="modal fade" id="aboutModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">About <?php echo SITE_NAME; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>
                    <strong><?php echo SITE_NAME; ?></strong> is a comprehensive management system 
                    for multiple courier companies, truck fleets, and delivery operations.
                </p>
                <p>
                    This system helps manage:
                </p>
                <ul>
                    <li>Multiple courier companies</li>
                    <li>Truck and vehicle fleets</li>
                    <li>Driver assignments</li>
                    <li>Order tracking and delivery</li>
                    <li>Client management</li>
                </ul>
                <p class="mb-0">
                    <small class="text-muted">
                        Developed with ❤️ for efficient logistics management.
                    </small>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Back to Top Button -->
<button onclick="topFunction()" id="backToTop" class="btn btn-primary rounded-circle shadow" 
        style="position: fixed; bottom: 20px; right: 20px; width: 50px; height: 50px; display: none;">
    <i class="fas fa-chevron-up"></i>
</button>

<script>
// Back to top button
window.onscroll = function() {scrollFunction()};

function scrollFunction() {
    const btn = document.getElementById("backToTop");
    if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
        btn.style.display = "block";
    } else {
        btn.style.display = "none";
    }
}

function topFunction() {
    document.body.scrollTop = 0;
    document.documentElement.scrollTop = 0;
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>