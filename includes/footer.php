    <!-- Simple Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="text-center text-gray-600">
                <p>&copy; <?php echo date('Y'); ?> Savings Group System. All rights reserved.</p>
                <p class="text-sm mt-1">A simple digital savings book for community groups</p>
            </div>
        </div>
    </footer>
    
    <!-- Simple JavaScript -->
    <script>
        // Show loading
        function showLoading() {
            document.getElementById('loading').classList.remove('hidden');
        }
        
        // Hide loading
        function hideLoading() {
            document.getElementById('loading').classList.add('hidden');
        }
        
        // Show toast message
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            let bgColor = 'bg-blue-100 border-blue-200 text-blue-800';
            let icon = 'fa-info-circle';
            
            if (type === 'success') {
                bgColor = 'bg-emerald-100 border-emerald-200 text-emerald-800';
                icon = 'fa-check-circle';
            } else if (type === 'error') {
                bgColor = 'bg-red-100 border-red-200 text-red-800';
                icon = 'fa-exclamation-circle';
            } else if (type === 'warning') {
                bgColor = 'bg-amber-100 border-amber-200 text-amber-800';
                icon = 'fa-exclamation-triangle';
            }
            
            toast.className = `border px-4 py-3 rounded ${bgColor} flex items-center`;
            toast.innerHTML = `
                <i class="fas ${icon} mr-3"></i>
                <span>${message}</span>
                <button class="ml-auto text-gray-500 hover:text-gray-700" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Format currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-KE', {
                style: 'currency',
                currency: 'KES'
            }).format(amount);
        }
        
        // Confirm action
        function confirmAction(message) {
            return confirm(message);
        }
        
        // Mobile menu toggle (if you have one)
        function toggleMobileMenu() {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('active');
            }
        }
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target)) {
                if (event.target.id !== 'menuToggle' && !event.target.closest('#menuToggle')) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.alert-auto-hide');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.transition = 'opacity 0.5s';
                    message.style.opacity = '0';
                    setTimeout(() => message.remove(), 500);
                }, 5000);
            });
        });
        
        // Handle form submissions - disable button
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                        
                        // Re-enable after 5 seconds (in case of error)
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 5000);
                    }
                });
            });
        });
    </script>
</body>
</html>