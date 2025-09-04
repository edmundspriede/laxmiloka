/**
 * Plugin Name: JetAppointments Service Manager
 * Description: Manage JetAppointments services with shortcodes - Create, Edit, and List services
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class JetAppointmentsServiceManager {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_create_service', array($this, 'ajax_create_service'));
        add_action('wp_ajax_nopriv_create_service', array($this, 'ajax_create_service'));
        add_action('wp_ajax_update_service', array($this, 'ajax_update_service'));
        add_action('wp_ajax_nopriv_update_service', array($this, 'ajax_update_service'));
        add_action('wp_ajax_delete_service', array($this, 'ajax_delete_service'));
        add_action('wp_ajax_nopriv_delete_service', array($this, 'ajax_delete_service'));
        add_action('wp_ajax_refresh_services_list', array($this, 'ajax_refresh_services_list'));
        add_action('wp_ajax_nopriv_refresh_services_list', array($this, 'ajax_refresh_services_list'));

        // Ensure WooCommerce price sync and correct checkout display
        add_action('woocommerce_new_product', array($this, 'sync_jetappointment_price_to_wc'), 10, 1);
        add_action('woocommerce_update_product', array($this, 'sync_jetappointment_price_to_wc'), 10, 1);
        add_filter('woocommerce_product_get_price', array($this, 'get_jetappointment_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'get_jetappointment_price'), 10, 2);
    }
    
    public function init() {
        // Register shortcodes
        add_shortcode('jet_service_form', array($this, 'service_form_shortcode'));
        add_shortcode('jet_service_list', array($this, 'service_list_shortcode'));
        add_shortcode('jet_service_edit', array($this, 'service_edit_shortcode'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'jet_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jet_service_nonce')
        ));
    }
    
    /**
     * Shortcode: [jet_service_form] - Create new service form
     */
    public function service_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'provider_id' => get_current_user_id(),
            'show_title' => 'true',
            'edit_id' => ''
        ), $atts);
        
        // Check if we're in edit mode
        $edit_id = $atts['edit_id'] ?: (isset($_GET['edit_service']) ? $_GET['edit_service'] : '');
        $is_edit_mode = !empty($edit_id);
        $service_data = null;
        
        if ($is_edit_mode) {
            $service_data = $this->get_jet_service_by_id($edit_id);
            if (!$service_data) {
                return '<p>Service not found.</p>';
            }
        }
        
        ob_start();
        ?>
        <style>
            .jet-service-manager { max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .jet-form-container { background: #fff; padding: 0; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); margin-bottom: 30px; overflow: hidden; }
            .jet-form-header { background: #333; color: white; padding: 20px; text-align: center; }
            
            /* Progress Bar */
            .jet-progress-container { background: rgba(255,255,255,0.2); height: 6px; margin-top: 20px; border-radius: 3px; overflow: hidden; }
            .jet-progress-bar { background: #fff; height: 100%; transition: width 0.3s ease; border-radius: 3px; }
            .jet-step-indicators { display: flex; justify-content: space-between; margin-top: 15px; }
            .jet-step-indicator { color: rgba(255,255,255,0.6); font-size: 14px; font-weight: 500; }
            .jet-step-indicator.active { color: #fff; }
            .jet-step-indicator.completed { color: #10b981; }
            
            /* Multi-step Form */
            .jet-form-content { padding: 40px; }
            .jet-form-step { display: none; }
            .jet-form-step.active { display: block; animation: slideIn 0.3s ease; }
            @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
            
            .jet-step-title { color: #1f2937; font-size: 24px; font-weight: 700; margin-bottom: 8px; }
            .jet-step-subtitle { color: #6b7280; font-size: 16px; margin-bottom: 30px; }
            
            .jet-form-group { margin-bottom: 24px; }
            .jet-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
            .jet-form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 14px; }
            .jet-form-group input, .jet-form-group textarea, .jet-form-group select { 
                width: 100%; padding: 14px 16px; border: 2px solid #e5e7eb; border-radius: 12px; 
                font-size: 16px; transition: all 0.3s ease; background: #fff;
            }
            .jet-form-group input:focus, .jet-form-group textarea:focus, .jet-form-group select:focus { 
                outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); 
            }
            
            /* Step-specific styling */
            .jet-pricing-section { background: #666; color: white; padding: 30px; border-radius: 16px; margin: 20px 0; }
            .jet-pricing-section input, .jet-pricing-section select { background: rgba(255,255,255,0.95); color: #333; }
            .jet-pricing-section label { color: rgba(255,255,255,0.9); }
            
            .jet-schedule-section { background: #f8fafc; padding: 30px; border-radius: 16px; margin: 20px 0; border: 1px solid #e2e8f0; }
            .jet-time-slot { display: grid; grid-template-columns: 120px 1fr 1fr; gap: 16px; align-items: center; margin-bottom: 16px; padding: 16px; background: white; border-radius: 12px; border: 1px solid #e2e8f0; }
            
            /* Review Section */
            .jet-review-section { background: #f9fafb; padding: 24px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #e5e7eb; }
            .jet-review-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
            .jet-review-item:last-child { border-bottom: none; }
            .jet-review-label { font-weight: 600; color: #374151; }
            .jet-review-value { color: #6b7280; }
            
            /* Buttons */
            .jet-form-navigation { display: flex; justify-content: space-between; align-items: center; margin-top: 40px; padding-top: 30px; border-top: 1px solid #e5e7eb; }
            .jet-btn { background: #333; color: white; padding: 14px 28px; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
            .jet-btn:hover { background: #555; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }
            .jet-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
            .jet-btn-secondary { background: #6b7280; }
            .jet-btn-secondary:hover { background: #4b5563; box-shadow: 0 10px 20px rgba(107, 114, 128, 0.3); }
            
            /* Success Popup */
            .jet-popup-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: none; align-items: center; justify-content: center; }
            .jet-popup { background: white; padding: 40px; border-radius: 20px; text-align: center; max-width: 400px; width: 90%; box-shadow: 0 25px 50px rgba(0,0,0,0.2); transform: scale(0.8); transition: transform 0.3s ease; }
            .jet-popup.show { transform: scale(1); }
            .jet-popup-icon { font-size: 48px; margin-bottom: 20px; }
            .jet-popup-title { font-size: 24px; font-weight: 700; color: #10b981; margin-bottom: 12px; }
            .jet-popup-message { color: #6b7280; margin-bottom: 30px; line-height: 1.5; }
            .jet-popup-btn { background: #10b981; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
            
            .jet-message { padding: 15px; border-radius: 12px; margin-bottom: 20px; display: none; }
            .jet-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
            .jet-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
            .jet-loading { opacity: 0.6; pointer-events: none; }
            
            /* Responsive */
            @media (max-width: 768px) {
                .jet-form-row { grid-template-columns: 1fr; gap: 16px; }
                .jet-form-content { padding: 24px; }
                .jet-time-slot { grid-template-columns: 1fr; gap: 12px; }
                .jet-form-navigation { flex-direction: column; gap: 16px; }
            }
        </style>
        
        <div class="jet-service-manager">
            <?php if ($atts['show_title'] === 'true'): ?>
            <div class="jet-form-header">
                <h2><?php echo $is_edit_mode ? '✏️ Edit Service' : '🚀 Create New Service'; ?></h2>
                <p><?php echo $is_edit_mode ? 'Update your appointment service details' : 'Set up your appointment service with custom scheduling'; ?></p>
                
                <!-- Progress Bar -->
                <div class="jet-progress-container">
                    <div class="jet-progress-bar" id="jet-progress-bar" style="width: 25%;"></div>
                </div>
                
                <!-- Step Indicators -->
                <div class="jet-step-indicators">
                    <span class="jet-step-indicator active" data-step="1">Basic Info</span>
                    <span class="jet-step-indicator" data-step="2">Pricing</span>
                    <span class="jet-step-indicator" data-step="3">Schedule</span>
                    <span class="jet-step-indicator" data-step="4">Review</span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="jet-form-container">
                <div class="jet-form-content">
                    <div id="jet-message" class="jet-message"></div>
                    
                    <form id="jet-service-form">
                        <!-- Step 1: Basic Information -->
                        <div class="jet-form-step active" data-step="1">
                            <h3 class="jet-step-title">📝 Basic Information</h3>
                            <p class="jet-step-subtitle">Let's start with the essential details of your service</p>
                            
                            <div class="jet-form-group">
                                <label for="jet-name">Service Name *</label>
                                <input type="text" id="jet-name" name="name" required placeholder="Enter your service name" value="<?php echo $is_edit_mode ? esc_attr($service_data['name']) : ''; ?>">
                            </div>
                            
                            <div class="jet-form-group">
                                <label for="jet-slug">URL Slug</label>
                                <input type="text" id="jet-slug" name="slug" placeholder="auto-generated-from-name" value="<?php echo $is_edit_mode ? esc_attr($service_data['slug']) : ''; ?>">
                            </div>
                            
                            <!-- Hidden status field - defaults to publish -->
                            <input type="hidden" id="jet-status" name="status" value="<?php echo $is_edit_mode ? esc_attr($service_data['status']) : 'publish'; ?>">
                            
                            <!-- Hidden edit ID field for edit mode -->
                            <?php if ($is_edit_mode): ?>
                            <input type="hidden" id="jet-edit-id" name="edit_id" value="<?php echo esc_attr($edit_id); ?>">
                            <?php endif; ?>
                            
                            <div class="jet-form-group">
                                <label for="jet-description">Description</label>
                                <textarea id="jet-description" name="description" rows="4" placeholder="Detailed description of your service"><?php echo $is_edit_mode ? esc_textarea($service_data['description']) : ''; ?></textarea>
                            </div>
                            
                            <div class="jet-form-group">
                                <label for="jet-short-description">Short Description</label>
                                <textarea id="jet-short-description" name="short_description" rows="2" placeholder="Brief summary for listings"><?php echo $is_edit_mode ? esc_textarea($service_data['short_description']) : ''; ?></textarea>
                            </div>
                            
                            <!-- Speciality Taxonomy (checkboxes) -->
                            <?php
                            // Fetch Speciality terms
                            $speciality_terms = get_terms(array(
                                'taxonomy' => 'speciality',
                                'hide_empty' => false,
                            ));
                            // Determine currently selected term IDs in edit mode
                            $selected_specialities = array();
                            if ($is_edit_mode && !empty($edit_id) && taxonomy_exists('speciality')) {
                                $assigned = get_the_terms(intval($edit_id), 'speciality');
                                if (!is_wp_error($assigned) && !empty($assigned)) {
                                    $selected_specialities = wp_list_pluck($assigned, 'term_id');
                                }
                            }
                            if (!is_wp_error($speciality_terms) && !empty($speciality_terms)) : ?>
                            <div class="jet-form-group">
                                <label>Speciality</label>
                                <div class="jet-select-dropdown" id="jet-speciality-dropdown" style="position:relative; max-width: 520px;">
                                    <button type="button" class="jet-btn jet-btn-secondary" id="jet-speciality-toggle" style="width:100%; justify-content: space-between; display:flex; align-items:center; gap:8px;">
                                        <span id="jet-speciality-summary">Select specialities</span>
                                        <span>▾</span>
                                    </button>
                                    <div class="jet-select-panel" id="jet-speciality-panel" style="position:absolute; z-index: 50; left:0; right:0; margin-top:6px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 10px 20px rgba(0,0,0,0.08); padding:10px; display:none; max-height:220px; overflow:auto;">
                                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                                            <?php foreach ($speciality_terms as $term): ?>
                                                <label style="display:flex; align-items:center; gap:6px; background:#f8fafc; padding:6px 10px; border-radius:8px;">
                                                    <input type="checkbox" name="speciality[]" value="<?php echo esc_attr($term->term_id); ?>" data-name="<?php echo esc_attr($term->name); ?>" <?php echo in_array($term->term_id, $selected_specialities, true) ? 'checked' : ''; ?> />
                                                    <span><?php echo esc_html($term->name); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <small style="color:#6b7280; display:block; margin-top:6px;">Select one or more specialities that apply to this service.</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Step 2: Pricing Configuration -->
                        <div class="jet-form-step" data-step="2">
                            <h3 class="jet-step-title"> Pricing Configuration</h3>
                            <p class="jet-step-subtitle">Set up your service pricing</p>
                            
                            <div class="jet-pricing-section">
                                <!-- Hidden pricing type field - defaults to fixed price -->
                                <input type="hidden" id="jet-price-type" name="price_type" value="<?php echo $is_edit_mode ? esc_attr($service_data['price_type']) : '_app_price'; ?>">
                                
                                <!-- Hidden provider ID field - uses current user -->
                                <input type="hidden" id="jet-provider-id" name="provider_id" value="<?php echo esc_attr($atts['provider_id']); ?>">
                                
                                <!-- Hidden WooCommerce price field - synced with service price -->
                                <input type="hidden" id="jet-regular-price" name="regular_price" value="<?php echo $is_edit_mode ? esc_attr($service_data['regular_price']) : ''; ?>">
                                
                                <div class="jet-form-group">
                                    <label for="jet-app-price">Service Price *</label>
                                    <input type="number" id="jet-app-price" name="app_price" step="0.01" min="0" required placeholder="Enter your service price" value="<?php echo $is_edit_mode ? esc_attr($service_data['price']) : ''; ?>">
                                    <small style="color: rgba(255,255,255,0.8); margin-top: 8px; display: block;">This will be the fixed price for your service</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Schedule Configuration -->
                        <div class="jet-form-step" data-step="3">
                            <h3 class="jet-step-title"> Schedule Configuration</h3>
                            <p class="jet-step-subtitle">Configure appointment duration and working hours</p>
                            
                            <div class="jet-schedule-section">
                                <div class="jet-form-row">
                                    <div class="jet-form-group">
                                        <label for="jet-duration">Appointment Duration</label>
                                        <select id="jet-duration" name="step_duration">
                                            <option value="1800" <?php echo ($is_edit_mode && $service_data['duration'] == 1800) ? 'selected' : ''; ?>>30 minutes</option>
                                            <option value="3600" <?php echo ($is_edit_mode && $service_data['duration'] == 3600) ? 'selected' : ''; ?>>60 minutes</option>
                                            <option value="5400" <?php echo ($is_edit_mode && $service_data['duration'] == 5400) ? 'selected' : ''; ?>>90 minutes</option>
                                            <option value="7200" <?php echo ($is_edit_mode && $service_data['duration'] == 7200) ? 'selected' : ''; ?>>120 minutes</option>
                                        </select>
                                    </div>
                                    <div class="jet-form-group">
                                        <label for="jet-booking-range">Booking Range (days)</label>
                                        <input type="number" id="jet-booking-range" name="booking_range" value="<?php echo $is_edit_mode ? esc_attr($service_data['booking_range']) : '90'; ?>" min="1" max="365">
                                    </div>
                                </div>
                                
                                <!-- Working Hours -->
                                <h4>Working Hours</h4>
                                <p style="color: #6b7280; font-size: 14px; margin-bottom: 20px;"><?php echo $is_edit_mode ? 'Current working hours for this service:' : 'Default hours are set to 9:00 AM - 6:00 PM. You can modify them as needed.'; ?></p>
                                <?php 
                                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                foreach ($days as $day): 
                                    // Get existing hours for this day if in edit mode
                                    $from_time = '09:00';
                                    $to_time = '18:00';
                                    $has_hours = false;
                                    if ($is_edit_mode && isset($service_data['working_hours'][$day]) && !empty($service_data['working_hours'][$day])) {
                                        $from_time = $service_data['working_hours'][$day][0]['from'];
                                        $to_time = $service_data['working_hours'][$day][0]['to'];
                                        $has_hours = true;
                                    }
                                    // If not edit mode, default all days active. If edit mode, active when there are hours.
                                    $is_active_day = $is_edit_mode ? $has_hours : true;
                                ?>
                                <div class="jet-time-slot">
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" class="jet-day-active" data-day="<?php echo esc_attr($day); ?>" <?php echo $is_active_day ? 'checked' : ''; ?> />
                                        <span><?php echo ucfirst($day); ?></span>
                                    </label>
                                    <input type="time" name="<?php echo $day; ?>_from" value="<?php echo esc_attr($from_time); ?>" placeholder="From" <?php echo $is_active_day ? '' : 'disabled'; ?>>
                                    <input type="time" name="<?php echo $day; ?>_to" value="<?php echo esc_attr($to_time); ?>" placeholder="To" <?php echo $is_active_day ? '' : 'disabled'; ?>>
                                </div>
                                <?php endforeach; ?>

                                <!-- Days Off -->
                                <div class="jet-form-group" style="margin-top: 24px;">
                                    <h4>Days Off</h4>
                                    <p style="color: #6b7280; font-size: 14px; margin-bottom: 12px;">Select dates when you are not available and optionally add a reason (e.g., events, Christmas, personal leave).</p>
                                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                        <input type="date" id="jet-day-off-input" style="max-width: 220px;" />
                                        <input type="text" id="jet-day-off-reason" placeholder="Reason (optional)" style="max-width: 280px;" />
                                        <button type="button" id="jet-add-day-off" class="jet-btn jet-btn-secondary">Add Day Off</button>
                                    </div>
                                    <div id="jet-days-off-list" style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;"></div>
                                    <!-- Hidden container to host inputs days_off[][date]/[reason] -->
                                    <div id="jet-days-off-inputs">
                                        <?php if ($is_edit_mode && !empty($service_data['days_off'])): ?>
                                            <?php $i = 0; foreach ($service_data['days_off'] as $off_date): ?>
                                                <?php 
                                                    $date_val = '';
                                                    $reason_val = '';
                                                    if (is_array($off_date)) {
                                                        // Support JetAppointments structure
                                                        if (isset($off_date['start']) && !empty($off_date['start'])) {
                                                            // Convert d-m-Y to Y-m-d for input value
                                                            $dt = DateTime::createFromFormat('d-m-Y', $off_date['start']);
                                                            if ($dt) { $date_val = $dt->format('Y-m-d'); }
                                                        } elseif (isset($off_date['from'])) {
                                                            $date_val = $off_date['from'];
                                                        }
                                                        // Reason/label/name support
                                                        if (isset($off_date['name'])) {
                                                            $reason_val = $off_date['name'];
                                                        } elseif (isset($off_date['label'])) {
                                                            $reason_val = $off_date['label'];
                                                        } elseif (isset($off_date['reason'])) {
                                                            $reason_val = $off_date['reason'];
                                                        }
                                                    } else {
                                                        $date_val = $off_date;
                                                    }
                                                ?>
                                                <?php if (!empty($date_val)): ?>
                                                    <div class="jet-day-off-input-group" data-date="<?php echo esc_attr($date_val); ?>">
                                                        <input type="hidden" name="days_off[<?php echo $i; ?>][date]" value="<?php echo esc_attr($date_val); ?>" />
                                                        <input type="hidden" name="days_off[<?php echo $i; ?>][reason]" value="<?php echo esc_attr($reason_val); ?>" />
                                                    </div>
                                                    <?php $i++; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Review & Submit -->
                        <div class="jet-form-step" data-step="4">
                            <h3 class="jet-step-title"> Review & Submit</h3>
                            <p class="jet-step-subtitle">Please review your service details before creating</p>
                            
                            <div class="jet-review-section" id="jet-review-content">
                                <!-- Review content will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="jet-form-navigation">
                            <button type="button" id="jet-prev-btn" class="jet-btn jet-btn-secondary" style="display: none;">← Previous</button>
                            <div></div>
                            <button type="button" id="jet-next-btn" class="jet-btn">Next →</button>
                            <button type="submit" id="jet-submit-btn" class="jet-btn" style="display: none;"><?php echo $is_edit_mode ? ' Update Service' : ' Create Service'; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Success Popup -->
        <div class="jet-popup-overlay" id="jet-success-popup">
            <div class="jet-popup">
                <div class="jet-popup-icon">🎉</div>
                <h3 class="jet-popup-title">Service Created Successfully!</h3>
                <p class="jet-popup-message" id="jet-popup-message">Your service has been created and is ready to accept appointments.</p>
                <button class="jet-popup-btn" onclick="closeSuccessPopup()">Continue</button>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let currentStep = 1;
            const totalSteps = 4;
            
            // Multi-step navigation functions
            function updateProgress() {
                const progress = (currentStep / totalSteps) * 100;
                $('#jet-progress-bar').css('width', progress + '%');
                
                // Update step indicators
                $('.jet-step-indicator').each(function() {
                    const stepNum = parseInt($(this).data('step'));
                    $(this).removeClass('active completed');
                    
                    if (stepNum < currentStep) {
                        $(this).addClass('completed');
                    } else if (stepNum === currentStep) {
                        $(this).addClass('active');
                    }
                });
            }
            
            function showStep(step) {
                $('.jet-form-step').removeClass('active');
                $(`.jet-form-step[data-step="${step}"]`).addClass('active');
                
                // Update navigation buttons
                if (step === 1) {
                    $('#jet-prev-btn').hide();
                } else {
                    $('#jet-prev-btn').show();
                }
                
                if (step === totalSteps) {
                    $('#jet-next-btn').hide();
                    $('#jet-submit-btn').show();
                    populateReview();
                } else {
                    $('#jet-next-btn').show();
                    $('#jet-submit-btn').hide();
                }
                
                updateProgress();
            }
            
            function validateStep(step) {
                let isValid = true;
                const $currentStep = $(`.jet-form-step[data-step="${step}"]`);
                
                // Check required fields in current step
                $currentStep.find('input[required], select[required], textarea[required]').each(function() {
                    if (!$(this).val().trim()) {
                        $(this).focus();
                        isValid = false;
                        return false;
                    }
                });
                
                return isValid;
            }
            
            function populateReview() {
                const reviewContent = $('#jet-review-content');
                const formData = $('#jet-service-form').serializeArray();
                let reviewHTML = '';
                
                // Basic Information
                reviewHTML += '<h4> Basic Information</h4>';
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Service Name:</span><span class="jet-review-value">${$('#jet-name').val() || 'Not specified'}</span></div>`;
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">URL Slug:</span><span class="jet-review-value">${$('#jet-slug').val() || 'Not specified'}</span></div>`;
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Status:</span><span class="jet-review-value">Published</span></div>`;
                
                if ($('#jet-description').val()) {
                    reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Description:</span><span class="jet-review-value">${$('#jet-description').val().substring(0, 100)}${$('#jet-description').val().length > 100 ? '...' : ''}</span></div>`;
                }
                
                // Pricing
                reviewHTML += '<h4 style="margin-top: 20px;"> Pricing</h4>';
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Pricing Type:</span><span class="jet-review-value">Fixed Price</span></div>`;
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Service Price:</span><span class="jet-review-value">$${$('#jet-app-price').val() || '0.00'}</span></div>`;
                
                // Schedule
                reviewHTML += '<h4 style="margin-top: 20px;"> Schedule</h4>';
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Duration:</span><span class="jet-review-value">${$('#jet-duration option:selected').text()}</span></div>`;
                reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Booking Range:</span><span class="jet-review-value">${$('#jet-booking-range').val()} days</span></div>`;
                
                // Days Off (with reasons)
                const daysOff = [];
                $("#jet-days-off-inputs .jet-day-off-input-group").each(function(){
                    const dateVal = $(this).find('input[name$="[date]"]').val();
                    const reasonVal = $(this).find('input[name$="[reason]"]').val() || '';
                    daysOff.push({ date: dateVal, reason: reasonVal });
                });
                if (daysOff.length) {
                    const listHtml = daysOff.map(item => `<li>${item.date}${item.reason ? ' — ' + item.reason : ''}</li>`).join('');
                    reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Days Off:</span><span class="jet-review-value"><ul style="margin:0; padding-left:18px;">${listHtml}</ul></span></div>`;
                }
                
                // Speciality
                const specialities = [];
                $("#jet-service-form input[name='speciality[]']:checked").each(function(){
                    const name = $(this).data('name') || $(this).next('span').text() || $(this).val();
                    specialities.push(name);
                });
                if (specialities.length) {
                    const listHtml = specialities.map(item => `<li>${item}</li>`).join('');
                    reviewHTML += `<div class="jet-review-item"><span class="jet-review-label">Speciality:</span><span class="jet-review-value"><ul style="margin:0; padding-left:18px;">${listHtml}</ul></span></div>`;
                }
                
                reviewContent.html(reviewHTML);
            }
            
            // Navigation event handlers
            $('#jet-next-btn').on('click', function() {
                if (validateStep(currentStep)) {
                    if (currentStep < totalSteps) {
                        currentStep++;
                        showStep(currentStep);
                    }
                }
            });
            
            $('#jet-prev-btn').on('click', function() {
                if (currentStep > 1) {
                    currentStep--;
                    showStep(currentStep);
                }
            });
            
            // Auto-generate slug (preserved functionality)
            $('#jet-name').on('input', function() {
                const slug = $(this).val().toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-');
                $('#jet-slug').val(slug);
            });
            
            // Sync prices (preserved functionality)
            $('#jet-app-price').on('input', function() {
                $('#jet-regular-price').val($(this).val());
            });

            // Speciality dropdown behavior
            const $specDropdown = $('#jet-speciality-dropdown');
            const $specToggle = $('#jet-speciality-toggle');
            const $specPanel = $('#jet-speciality-panel');
            const $specSummary = $('#jet-speciality-summary');

            function updateSpecialitySummary() {
                const names = [];
                $("#jet-service-form input[name='speciality[]']:checked").each(function(){
                    const n = $(this).data('name') || $(this).next('span').text();
                    if (n) names.push(n);
                });
                if (names.length) {
                    $specSummary.text(names.join(', '));
                } else {
                    $specSummary.text('Select specialities');
                }
            }

            if ($specToggle.length) {
                $specToggle.on('click', function(e){
                    e.stopPropagation();
                    $specPanel.toggle();
                });
                // Close on outside click
                $(document).on('click', function(e){
                    if (!$(e.target).closest($specDropdown).length) {
                        $specPanel.hide();
                    }
                });
                // Update summary when selection changes
                $(document).on('change', "#jet-service-form input[name='speciality[]']", function(){
                    updateSpecialitySummary();
                });
                // Initial summary
                updateSpecialitySummary();
            }

            // Working Hours: day active toggles
            function setDayActiveState(day, isActive) {
                const $from = $(`input[name="${day}_from"]`);
                const $to = $(`input[name="${day}_to"]`);
                if (isActive) {
                    $from.prop('disabled', false);
                    $to.prop('disabled', false);
                } else {
                    // Clear values to avoid stale submission and disable
                    $from.val('').prop('disabled', true);
                    $to.val('').prop('disabled', true);
                }
            }

            $(document).on('change', '.jet-day-active', function(){
                const day = $(this).data('day');
                setDayActiveState(day, $(this).is(':checked'));
            });

            // Apply initial state for all checkboxes on load
            $('.jet-day-active').each(function(){
                const day = $(this).data('day');
                setDayActiveState(day, $(this).is(':checked'));
            });

            // Days Off UI handlers
            function renderDaysOffBadges() {
                const $list = $('#jet-days-off-list');
                $list.empty();
                $("#jet-days-off-inputs .jet-day-off-input-group").each(function(){
                    const dateVal = $(this).find('input[name$="[date]"]').val();
                    const reasonVal = $(this).find('input[name$="[reason]"]').val() || '';
                    const label = reasonVal ? `${dateVal} — ${reasonVal}` : dateVal;
                    const $badge = $(`<span style="background:#eef2ff;color:#3730a3;padding:6px 10px;border-radius:9999px;display:inline-flex;align-items:center;gap:6px;">
                        <span>${label}</span>
                        <button type="button" data-date="${dateVal}" class="remove-day-off" style="background:transparent;border:none;color:#1f2937;cursor:pointer;">✕</button>
                    </span>`);
                    $list.append($badge);
                });
            }

            $('#jet-add-day-off').on('click', function(){
                const dateVal = $('#jet-day-off-input').val();
                const reasonVal = $('#jet-day-off-reason').val();
                if (!dateVal) return;
                // Prevent duplicates by date
                const exists = !!$(`#jet-days-off-inputs .jet-day-off-input-group[data-date="${dateVal}"]`).length;
                if (!exists) {
                    const index = $('#jet-days-off-inputs .jet-day-off-input-group').length;
                    $('#jet-days-off-inputs').append(`
                        <div class="jet-day-off-input-group" data-date="${dateVal}">
                            <input type="hidden" name="days_off[${index}][date]" value="${dateVal}" />
                            <input type="hidden" name="days_off[${index}][reason]" value="${reasonVal ? reasonVal.replace(/"/g, '&quot;') : ''}" />
                        </div>
                    `);
                    renderDaysOffBadges();
                }
                $('#jet-day-off-input').val('');
                $('#jet-day-off-reason').val('');
            });

            $(document).on('click', '.remove-day-off', function(){
                const dateVal = $(this).data('date');
                $(`#jet-days-off-inputs .jet-day-off-input-group[data-date="${dateVal}"]`).remove();
                renderDaysOffBadges();
            });
            
            // Success popup functions
            window.showSuccessPopup = function(message, productId) {
                const popup = $('#jet-success-popup');
                const popupContent = popup.find('.jet-popup');
                
                if (productId) {
                    $('#jet-popup-message').html(`Your service has been created successfully!<br><strong>Product ID: ${productId}</strong>`);
                } else {
                    $('#jet-popup-message').html(message || 'Your service has been created and is ready to accept appointments.');
                }
                
                popup.css('display', 'flex');
                setTimeout(() => {
                    popupContent.addClass('show');
                }, 10);
            };
            
            window.closeSuccessPopup = function() {
                const popup = $('#jet-success-popup');
                const popupContent = popup.find('.jet-popup');
                
                popupContent.removeClass('show');
                setTimeout(() => {
                    popup.hide();
                    // Reset form and go back to step 1
                    $('#jet-service-form')[0].reset();
                    currentStep = 1;
                    showStep(currentStep);
                    
                    // Trigger services list refresh if it exists on the page
                    if (typeof refreshServicesList === 'function') {
                        refreshServicesList();
                    } else {
                        // Fallback: reload the page if refresh function doesn't exist
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    }
                }, 300);
            };
            
            // Form submission (preserved AJAX functionality with popup enhancement)
            $('#jet-service-form').on('submit', function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const $btn = $('#jet-submit-btn');
                const $message = $('#jet-message');
                const isEditMode = $('#jet-edit-id').length > 0;
                const originalBtnText = $btn.html();
                
                $btn.prop('disabled', true).html(isEditMode ? '⏳ Updating...' : '⏳ Creating...');
                $form.addClass('jet-loading');
                
                $.ajax({
                    url: jet_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: isEditMode ? 'update_service' : 'create_service',
                        nonce: jet_ajax.nonce,
                        form_data: $form.serialize()
                    },
                    success: function(response) {
                        if (response.success) {
                            // Show success popup instead of inline message
                            let successMessage = isEditMode ? 'Your service has been updated successfully!' : 'Your service has been created successfully!';
                            
                            // Add astrologer information if available
                            if (response.data.astrologer_name) {
                                successMessage += '<br><br><strong>Linked to:</strong> ' + response.data.astrologer_name;
                            }
                            
                            showSuccessPopup(successMessage, response.data.product_id);
                        } else {
                            $message.show().removeClass('jet-success').addClass('jet-error')
                                .html(' Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        $message.show().removeClass('jet-success').addClass('jet-error')
                            .html(' Network error occurred');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalBtnText);
                        $form.removeClass('jet-loading');
                    }
                });
            });
            
            // Initialize first step and hydrate badges if editing
            showStep(1);
            renderDaysOffBadges();
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [jet_service_list] - List all services
     */
    public function service_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'per_page' => 10,
            'show_edit' => 'true',
            'show_delete' => 'true',
            'provider_id' => ''
        ), $atts);
        
        // Automatically filter by current logged-in user's astrologer post
        if (is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $user_astrologer_post_id = $this->get_astrologer_post_id_by_user_id($current_user_id);
            
            if ($user_astrologer_post_id) {
                $atts['provider_id'] = $user_astrologer_post_id;
            }
        }
        
        $services = $this->get_jet_services($atts);
        
        ob_start();
        ?>
        <style>
            .jet-services-list { max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .jet-service-card { background: white; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); margin-bottom: 20px; padding: 25px; transition: all 0.3s ease; }
            .jet-service-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
            .jet-service-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
            .jet-service-title { color: #333; margin: 0; font-size: 1.5rem; }
            .jet-service-price { background: #333; color: white; padding: 8px 16px; border-radius: 20px; font-weight: 600; }
            .jet-service-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0; }
            .jet-meta-item { background: #f8f9fa; padding: 12px; border-radius: 8px; }
            .jet-meta-label { font-weight: 600; color: #666; font-size: 0.9rem; }
            .jet-meta-value { color: #333; margin-top: 5px; }
            .jet-service-actions { margin-top: 20px; }
            .jet-btn-small { padding: 8px 16px; font-size: 14px; margin-right: 10px; border-radius: 6px; text-decoration: none; display: inline-block; }
            .jet-btn-edit { background: #666; color: white; }
            .jet-btn-edit:hover { background: #555; }
            .jet-btn-delete { background: #dc3545; color: white; }
            .jet-btn-delete:hover { background: #c82333; }
            .jet-working-hours { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-top: 10px; }
            .jet-day-hours { background: white; padding: 8px; border-radius: 6px; text-align: center; font-size: 0.85rem; }
        </style>
        
        <div class="jet-services-list">
            <div class="jet-form-header">
                <h2> Services List</h2>
                <p>Manage your appointment services</p>
            </div>
            
            <?php if (empty($services)): ?>
                <div class="jet-service-card">
                    <p>No services found. <a href="#" onclick="location.reload()">Refresh</a> or create a new service.</p>
                </div>
            <?php else: ?>
                <?php foreach ($services as $service): ?>
                <div class="jet-service-card" data-service-id="<?php echo $service['id']; ?>">
                    <div class="jet-service-header">
                        <h3 class="jet-service-title"><?php echo esc_html($service['name']); ?></h3>
                        <span class="jet-service-price">$<?php echo esc_html($service['price']); ?></span>
                    </div>
                    
                    <div class="jet-service-meta">
                        <div class="jet-meta-item">
                            <div class="jet-meta-label">Status</div>
                            <div class="jet-meta-value"><?php echo ucfirst($service['status']); ?></div>
                        </div>
                        <div class="jet-meta-item">
                            <div class="jet-meta-label">Duration</div>
                            <div class="jet-meta-value"><?php echo ($service['duration'] / 60); ?> minutes</div>
                        </div>
                        <div class="jet-meta-item">
                            <div class="jet-meta-label">Booking Range</div>
                            <div class="jet-meta-value"><?php echo $service['booking_range']; ?> days</div>
                        </div>
                        <div class="jet-meta-item">
                            <div class="jet-meta-label">Provider</div>
                            <div class="jet-meta-value">
                                <?php if (!empty($service['astrologer_info'])): ?>
                                    <a href="<?php echo esc_url($service['astrologer_info']['permalink']); ?>" target="_blank">
                                        <?php echo esc_html($service['astrologer_info']['name']); ?>
                                    </a>
                                    <br><small>(ID: <?php echo $service['provider_id']; ?>)</small>
                                <?php else: ?>
                                    ID: <?php echo $service['provider_id']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($service['working_hours'])): ?>
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Working Hours</div>
                        <div class="jet-working-hours">
                            <?php foreach ($service['working_hours'] as $day => $hours): ?>
                                <div class="jet-day-hours">
                                    <strong><?php echo ucfirst(substr($day, 0, 3)); ?></strong><br>
                                    <?php if (!empty($hours)): ?>
                                        <?php echo $hours[0]['from'] . ' - ' . $hours[0]['to']; ?>
                                    <?php else: ?>
                                        Closed
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($service['description'])): ?>
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Description</div>
                        <div class="jet-meta-value"><?php echo wp_trim_words(strip_tags($service['description']), 20); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="jet-service-actions">
                        <?php if ($atts['show_edit'] === 'true'): ?>
                        <a href="#" class="jet-btn-small jet-btn-edit jet-edit-service" data-id="<?php echo $service['id']; ?>"> Edit</a>
                        <?php endif; ?>
                        <?php if ($atts['show_delete'] === 'true'): ?>
                        <a href="#" class="jet-btn-small jet-btn-delete jet-delete-service" data-id="<?php echo $service['id']; ?>"> Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Global refresh function for services list
            window.refreshServicesList = function() {
                const $servicesList = $('.jet-services-list');
                if ($servicesList.length) {
                    // Show loading state
                    $servicesList.html('<div class="jet-service-card"><p>Refreshing services...</p></div>');
                    
                    // Reload the services list via AJAX
                    $.ajax({
                        url: jet_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'refresh_services_list',
                            nonce: jet_ajax.nonce,
                            per_page: '<?php echo esc_attr($atts['per_page']); ?>',
                            show_edit: '<?php echo esc_attr($atts['show_edit']); ?>',
                            show_delete: '<?php echo esc_attr($atts['show_delete']); ?>',
                            provider_id: '<?php echo esc_attr($atts['provider_id']); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $servicesList.html(response.data.html);
                                // Reinitialize event handlers for new elements
                                initializeServiceListHandlers();
                            } else {
                                $servicesList.html('<div class="jet-service-card"><p>Error refreshing services. <a href="#" onclick="location.reload()">Reload page</a></p></div>');
                            }
                        },
                        error: function() {
                            $servicesList.html('<div class="jet-service-card"><p>Error refreshing services. <a href="#" onclick="location.reload()">Reload page</a></p></div>');
                        }
                    });
                }
            };
            
            // Initialize event handlers for service list
            function initializeServiceListHandlers() {
                // Edit service - redirect to edit form
                $('.jet-edit-service').off('click').on('click', function(e) {
                    e.preventDefault();
                    const serviceId = $(this).data('id');
                    
                    // Create a temporary form to redirect with the service ID
                    const editUrl = window.location.href.split('?')[0] + '?edit_service=' + serviceId;
                    window.location.href = editUrl;
                });
                
                // Delete service
                $('.jet-delete-service').off('click').on('click', function(e) {
                    e.preventDefault();
                    const serviceId = $(this).data('id');
                    const $card = $(this).closest('.jet-service-card');
                    
                    if (confirm('Are you sure you want to delete this service?')) {
                        $.ajax({
                            url: jet_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'delete_service',
                                nonce: jet_ajax.nonce,
                                service_id: serviceId
                            },
                            success: function(response) {
                                if (response.success) {
                                    $card.fadeOut(300, function() {
                                        $(this).remove();
                                    });
                                } else {
                                    alert('Error: ' + response.data.message);
                                }
                            }
                        });
                    }
                });
            }
            
            // Initialize handlers on page load
            initializeServiceListHandlers();
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [jet_service_edit id="123"] - Edit specific service
     */
    public function service_edit_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
        ), $atts);
        
        if (empty($atts['id'])) {
            return '<p>Service ID required for editing.</p>';
        }
        
        $service = $this->get_jet_service_by_id($atts['id']);
        if (!$service) {
            return '<p>Service not found.</p>';
        }
        
        // Similar to create form but pre-populated with service data
        ob_start();
        ?>
        <div class="jet-service-manager">
            <div class="jet-form-header">
                <h2> Edit Service: <?php echo esc_html($service['name']); ?></h2>
            </div>
            
            <div class="jet-form-container">
                <div id="jet-edit-message" class="jet-message"></div>
                
                <form id="jet-edit-service-form">
                    <input type="hidden" name="service_id" value="<?php echo esc_attr($service['id']); ?>">
                    
                    <div class="jet-form-group">
                        <label for="jet-edit-name">Service Name *</label>
                        <input type="text" id="jet-edit-name" name="name" value="<?php echo esc_attr($service['name']); ?>" required>
                    </div>
                    
                    <div class="jet-form-row">
                        <div class="jet-form-group">
                            <label for="jet-edit-slug">URL Slug</label>
                            <input type="text" id="jet-edit-slug" name="slug" value="<?php echo esc_attr($service['slug']); ?>">
                        </div>
                        <div class="jet-form-group">
                            <label for="jet-edit-status">Status</label>
                            <select id="jet-edit-status" name="status">
                                <option value="publish" <?php selected($service['status'], 'publish'); ?>>Published</option>
                                <option value="draft" <?php selected($service['status'], 'draft'); ?>>Draft</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="jet-form-group">
                        <label for="jet-edit-description">Description</label>
                        <textarea id="jet-edit-description" name="description" rows="4"><?php echo esc_textarea(strip_tags($service['description'])); ?></textarea>
                    </div>
                    
                    <div class="jet-form-group">
                        <label for="jet-edit-short-description">Short Description</label>
                        <textarea id="jet-edit-short-description" name="short_description" rows="2"><?php echo esc_textarea($service['short_description']); ?></textarea>
                    </div>
                    
                    <!-- Pricing Section -->
                    <div class="jet-pricing-section">
                        <h3> Pricing Configuration</h3>
                        <div class="jet-form-row">
                            <div class="jet-form-group">
                                <label for="jet-edit-app-price">Service Price</label>
                                <input type="number" id="jet-edit-app-price" name="app_price" step="0.01" min="0" value="<?php echo esc_attr($service['app_price']); ?>" required>
                            </div>
                            <div class="jet-form-group">
                                <label for="jet-edit-regular-price">WooCommerce Price</label>
                                <input type="number" id="jet-edit-regular-price" name="regular_price" step="0.01" min="0" value="<?php echo esc_attr($service['price']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="jet-btn"> Update Service</button>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#jet-edit-service-form').on('submit', function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const $btn = $form.find('button[type="submit"]');
                const $message = $('#jet-edit-message');
                
                $btn.prop('disabled', true).html(' Updating...');
                
                $.ajax({
                    url: jet_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'update_service',
                        nonce: jet_ajax.nonce,
                        form_data: $form.serialize()
                    },
                    success: function(response) {
                        $message.show();
                        if (response.success) {
                            $message.removeClass('jet-error').addClass('jet-success')
                                .html(' Service updated successfully!');
                        } else {
                            $message.removeClass('jet-success').addClass('jet-error')
                                .html(' Error: ' + response.data.message);
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(' Update Service');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX: Create new service
     */
    public function ajax_create_service() {
        check_ajax_referer('jet_service_nonce', 'nonce');
        
        parse_str($_POST['form_data'], $form_data);
        
        // Build working hours
        $working_hours = array();
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($days as $day) {
            $from_key = $day . '_from';
            $to_key = $day . '_to';
            
            if (!empty($form_data[$from_key]) && !empty($form_data[$to_key])) {
                $working_hours[$day] = array(
                    array(
                        'from' => sanitize_text_field($form_data[$from_key]),
                        'to' => sanitize_text_field($form_data[$to_key])
                    )
                );
            }
        }
        
        // Days off (support date + optional reason) → store with JetAppointments structure
        // Example item:
        // { start: "14-08-2025", startTimeStamp: "1755129600000", end: "14-08-2025", endTimeStamp: "1755129600000", name: "reason", type: "days_off", editIndex: "" }
        $days_off_meta = array();
        if (!empty($form_data['days_off']) && is_array($form_data['days_off'])) {
            foreach ($form_data['days_off'] as $entry) {
                if (is_array($entry)) {
                    $date_value = isset($entry['date']) ? sanitize_text_field($entry['date']) : '';
                    $reason_value = isset($entry['reason']) ? sanitize_text_field($entry['reason']) : '';
                } else {
                    $date_value = sanitize_text_field($entry);
                    $reason_value = '';
                }
                if (!empty($date_value)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $date_value);
                    if ($dt) {
                        $dt->setTime(0, 0, 0);
                        $start_formatted = $dt->format('d-m-Y');
                        $timestamp_ms = strval($dt->getTimestamp() * 1000);
                        $days_off_meta[] = array(
                            'start' => $start_formatted,
                            'startTimeStamp' => $timestamp_ms,
                            'end' => $start_formatted,
                            'endTimeStamp' => $timestamp_ms,
                            'name' => $reason_value,
                            'type' => 'days_off',
                            'editIndex' => ''
                        );
                    }
                }
            }
        }

        $step_duration = intval($form_data['step_duration']);
        $app_price = floatval($form_data['app_price']);
        $price_per_minute = round($app_price / ($step_duration / 60), 2);
        
        // Get the astrologer post ID for the current user
        $user_id = intval($form_data['provider_id']);
        $astrologer_post_id = $this->get_astrologer_post_id_by_user_id($user_id);
        
        // Temporary fallback: If lookup fails, try to use a known astrologer post ID
        if (!$astrologer_post_id) {
            // Check if we're dealing with user ID 2 and try to use astrologer post 250
            if ($user_id == 2) {
                $post_250 = get_post(250);
                if ($post_250 && $post_250->post_type === 'astrologers' && $post_250->post_status === 'publish') {
                    $astrologer_post_id = 250;
                    error_log("JetAppointments Service Manager: Using fallback astrologer post ID 250 for user ID 2");
                }
            }
        }
        
        if (!$astrologer_post_id) {
            wp_send_json_error(array(
                'message' => 'Astrologer profile not found for this user. Please ensure your astrologer profile is created first. User ID: ' . $user_id
            ));
        }
        
        // If we're using fallback astrologer post ID 250 for user ID 2, link them
        if ($astrologer_post_id == 250 && $user_id == 2) {
            $this->link_user_to_astrologer_post($user_id, $astrologer_post_id);
        }
        
        // Prepare product data with WooCommerce pricing
        $product_data = array(
            'name' => sanitize_text_field($form_data['name']),
            'slug' => sanitize_text_field($form_data['slug']),
            'type' => 'simple',
            'status' => sanitize_text_field($form_data['status']),
            'catalog_visibility' => 'visible',
            'description' => '<p>' . sanitize_textarea_field($form_data['description']) . '</p>',
            'short_description' => sanitize_text_field($form_data['short_description']),
            'virtual' => true,
            'downloadable' => false,
            'manage_stock' => false,
            'reviews_allowed' => false,
            'regular_price' => strval($app_price),
            'price' => strval($app_price),
            'sale_price' => '',
            'meta_data' => array(
                array(
                    'key' => 'jet_apb_post_meta',
                    'value' => array(
                        'custom_schedule' => array(
                            'use_custom_schedule' => true,
                            'step_duration' => $step_duration,
                            'max_duration' => $step_duration,
                            'default_slot' => $step_duration,
                            'min_slot_count' => 1,
                            'min_recurring_count' => '1',
                            'max_recurring_count' => '3',
                            // Allow rewriting days-off & set working days mode defaults to align with REST example
                            'working_days_mode' => 'override_full',
                            'days_off_allow_rewrite' => 'allow',
                            'daysOffAllowRewrite' => 'allow',
                            'appointments_range' => array(
                                'type' => 'range',
                                'range_num' => strval(intval($form_data['booking_range'])),
                                'range_unit' => 'days'
                            ),
                            're_booking' => array('day'),
                            'working_hours' => $working_hours,
                            'days_off' => $days_off_meta
                        ),
                        'meta_settings' => array(
                            'price_type' => sanitize_text_field($form_data['price_type']),
                            '_app_price' => $app_price,
                            '_app_price_hour' => $app_price,
                            '_app_price_minute' => $price_per_minute
                        )
                    )
                ),
                array(
                    'key' => '_app_price',
                    'value' => $app_price
                ),
                array(
                    'key' => $this->get_relationship_meta_key(),
                    'value' => strval($astrologer_post_id)
                )
            )
        );
        
        // Create product via WooCommerce REST API
        $result = $this->create_woocommerce_product($product_data);
        
        if ($result['success']) {
            // Set the product author to the submitting user so WP admin shows correct author
            $this->set_product_author($result['product_id'], $user_id);
            // Update the astrologer post to include this product in its relation field (bidirectional sync)
            $this->sync_astrologer_product_relationship($astrologer_post_id, $result['product_id']);
            // Ensure price synced in meta and WC fields
            $this->force_price_sync($result['product_id'], $app_price);
            
            // Assign Speciality taxonomy terms if provided
            if (taxonomy_exists('speciality')) {
                $speciality_terms = array();
                if (!empty($form_data['speciality'])) {
                    $speciality_terms = array_map('intval', (array) $form_data['speciality']);
                }
                // Set (or clear) terms on the product
                wp_set_object_terms($result['product_id'], $speciality_terms, 'speciality', false);
            }
            
            // Get astrologer info for success message
            $astrologer_info = $this->get_astrologer_info_by_post_id($astrologer_post_id);
            $astrologer_name = $astrologer_info ? $astrologer_info['name'] : 'Unknown Astrologer';
            
            wp_send_json_success(array(
                'message' => "Service created successfully and linked to astrologer: $astrologer_name!",
                'product_id' => $result['product_id'],
                'permalink' => $result['permalink'],
                'astrologer_name' => $astrologer_name,
                'debug_price' => $app_price
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }

    /**
     * Ensure created product shows correct author in WP backend
     */
    private function set_product_author($product_id, $user_id) {
        $update_args = array(
            'ID' => intval($product_id),
            'post_author' => intval($user_id)
        );
        $result = wp_update_post($update_args, true);
        if (is_wp_error($result)) {
            error_log('JetAppointments Service Manager: Failed to set product author for product ' . $product_id . ' - ' . $result->get_error_message());
        }
    }
    
    /**
     * AJAX: Update existing service
     */
    public function ajax_update_service() {
        check_ajax_referer('jet_service_nonce', 'nonce');
        
        parse_str($_POST['form_data'], $form_data);
        $service_id = intval($form_data['edit_id']);
        
        // Process working hours
        $working_hours = array();
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            $from_key = $day . '_from';
            $to_key = $day . '_to';
            if (!empty($form_data[$from_key]) && !empty($form_data[$to_key])) {
                $working_hours[$day] = array(
                    array(
                        'from' => sanitize_text_field($form_data[$from_key]),
                        'to' => sanitize_text_field($form_data[$to_key])
                    )
                );
            }
        }
        
        // Days off (support date + optional reason) → store with JetAppointments structure
        $days_off_meta = array();
        if (!empty($form_data['days_off']) && is_array($form_data['days_off'])) {
            foreach ($form_data['days_off'] as $entry) {
                if (is_array($entry)) {
                    $date_value = isset($entry['date']) ? sanitize_text_field($entry['date']) : '';
                    $reason_value = isset($entry['reason']) ? sanitize_text_field($entry['reason']) : '';
                } else {
                    $date_value = sanitize_text_field($entry);
                    $reason_value = '';
                }
                if (!empty($date_value)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $date_value);
                    if ($dt) {
                        $dt->setTime(0, 0, 0);
                        $start_formatted = $dt->format('d-m-Y');
                        $timestamp_ms = strval($dt->getTimestamp() * 1000);
                        $days_off_meta[] = array(
                            'start' => $start_formatted,
                            'startTimeStamp' => $timestamp_ms,
                            'end' => $start_formatted,
                            'endTimeStamp' => $timestamp_ms,
                            'name' => $reason_value,
                            'type' => 'days_off',
                            'editIndex' => ''
                        );
                    }
                }
            }
        }
        
        $step_duration = intval($form_data['step_duration']);
        $app_price = floatval($form_data['app_price']);
        $price_per_minute = round($app_price / ($step_duration / 60), 2);
        
        // Get the astrologer post ID for the current user
        $user_id = intval($form_data['provider_id']);
        $astrologer_post_id = $this->get_astrologer_post_id_by_user_id($user_id);
        
        // Temporary fallback: If lookup fails, try to use a known astrologer post ID
        if (!$astrologer_post_id) {
            // Check if we're dealing with user ID 2 and try to use astrologer post 250
            if ($user_id == 2) {
                $post_250 = get_post(250);
                if ($post_250 && $post_250->post_type === 'astrologers' && $post_250->post_status === 'publish') {
                    $astrologer_post_id = 250;
                    error_log("JetAppointments Service Manager: Using fallback astrologer post ID 250 for user ID 2");
                }
            }
        }
        
        if (!$astrologer_post_id) {
            wp_send_json_error(array(
                'message' => 'Astrologer profile not found for this user. Please ensure your astrologer profile is created first. User ID: ' . $user_id
            ));
        }
        
        // If we're using fallback astrologer post ID 250 for user ID 2, link them
        if ($astrologer_post_id == 250 && $user_id == 2) {
            $this->link_user_to_astrologer_post($user_id, $astrologer_post_id);
        }
        
        // Prepare update data with WooCommerce pricing
        $update_data = array(
            'name' => sanitize_text_field($form_data['name']),
            'slug' => sanitize_text_field($form_data['slug']),
            'status' => sanitize_text_field($form_data['status']),
            'description' => '<p>' . sanitize_textarea_field($form_data['description']) . '</p>',
            'short_description' => sanitize_text_field($form_data['short_description']),
            'regular_price' => strval($app_price),
            'price' => strval($app_price),
            'sale_price' => '',
            'meta_data' => array(
                array(
                    'key' => 'jet_apb_post_meta',
                    'value' => array(
                        'custom_schedule' => array(
                            'use_custom_schedule' => true,
                            'step_duration' => $step_duration,
                            'max_duration' => $step_duration,
                            'default_slot' => $step_duration,
                            'min_slot_count' => 1,
                            'min_recurring_count' => '1',
                            'max_recurring_count' => '3',
                            'working_days_mode' => 'override_full',
                            'days_off_allow_rewrite' => 'allow',
                            'daysOffAllowRewrite' => 'allow',
                            'appointments_range' => array(
                                'type' => 'range',
                                'range_num' => strval(intval($form_data['booking_range'])),
                                'range_unit' => 'days'
                            ),
                            're_booking' => array('day'),
                            'working_hours' => $working_hours,
                            'days_off' => $days_off_meta
                        ),
                        'meta_settings' => array(
                            'price_type' => sanitize_text_field($form_data['price_type']),
                            '_app_price' => $app_price,
                            '_app_price_hour' => $app_price,
                            '_app_price_minute' => $price_per_minute
                        )
                    )
                ),
                array(
                    'key' => '_app_price',
                    'value' => $app_price
                ),
                array(
                    'key' => $this->get_relationship_meta_key(),
                    'value' => strval($astrologer_post_id)
                )
            )
        );
        
        $result = $this->update_woocommerce_product($service_id, $update_data);
        
        if ($result['success']) {
            // Update the astrologer post to include this product in its relation field (bidirectional sync)
            $this->sync_astrologer_product_relationship($astrologer_post_id, $service_id);
            // Ensure price synced in meta and WC fields
            $this->force_price_sync($service_id, $app_price);
            
            // Assign Speciality taxonomy terms if provided
            if (taxonomy_exists('speciality')) {
                $speciality_terms = array();
                if (!empty($form_data['speciality'])) {
                    $speciality_terms = array_map('intval', (array) $form_data['speciality']);
                }
                wp_set_object_terms($service_id, $speciality_terms, 'speciality', false);
            }
             
            wp_send_json_success(array(
                'message' => 'Service updated successfully!',
                'debug_price' => $app_price
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Delete service
     */
    public function ajax_delete_service() {
        check_ajax_referer('jet_service_nonce', 'nonce');
        
        $service_id = intval($_POST['service_id']);
        $result = $this->delete_woocommerce_product($service_id);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Service deleted successfully!'
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Refresh services list
     */
    public function ajax_refresh_services_list() {
        check_ajax_referer('jet_service_nonce', 'nonce');
        
        $atts = array(
            'per_page' => isset($_POST['per_page']) ? intval($_POST['per_page']) : 10,
            'show_edit' => isset($_POST['show_edit']) ? sanitize_text_field($_POST['show_edit']) : 'true',
            'show_delete' => isset($_POST['show_delete']) ? sanitize_text_field($_POST['show_delete']) : 'true',
            'provider_id' => isset($_POST['provider_id']) ? sanitize_text_field($_POST['provider_id']) : ''
        );
        
        $services = $this->get_jet_services($atts);
        
        ob_start();
        ?>
        <div class="jet-form-header">
            <h2> Services List</h2>
            <p>Manage your appointment services</p>
        </div>
        
        <?php if (empty($services)): ?>
            <div class="jet-service-card">
                <p>No services found. <a href="#" onclick="location.reload()">Refresh</a> or create a new service.</p>
            </div>
        <?php else: ?>
            <?php foreach ($services as $service): ?>
            <div class="jet-service-card" data-service-id="<?php echo $service['id']; ?>">
                <div class="jet-service-header">
                    <h3 class="jet-service-title"><?php echo esc_html($service['name']); ?></h3>
                    <span class="jet-service-price">$<?php echo esc_html($service['price']); ?></span>
                </div>
                
                <div class="jet-service-meta">
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Status</div>
                        <div class="jet-meta-value"><?php echo ucfirst($service['status']); ?></div>
                    </div>
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Duration</div>
                        <div class="jet-meta-value"><?php echo ($service['duration'] / 60); ?> minutes</div>
                    </div>
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Booking Range</div>
                        <div class="jet-meta-value"><?php echo $service['booking_range']; ?> days</div>
                    </div>
                    <div class="jet-meta-item">
                        <div class="jet-meta-label">Astrologer</div>
                        <div class="jet-meta-value">
                            <?php if (!empty($service['astrologer_info'])): ?>
                                <a href="<?php echo esc_url($service['astrologer_info']['permalink']); ?>" target="_blank">
                                    <?php echo esc_html($service['astrologer_info']['name']); ?>
                                </a>
                                <br><small>(ID: <?php echo $service['provider_id']; ?>)</small>
                            <?php else: ?>
                                ID: <?php echo $service['provider_id']; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($service['working_hours'])): ?>
                <div class="jet-meta-item">
                    <div class="jet-meta-label">Working Hours</div>
                    <div class="jet-working-hours">
                        <?php foreach ($service['working_hours'] as $day => $hours): ?>
                            <div class="jet-day-hours">
                                <strong><?php echo ucfirst(substr($day, 0, 3)); ?></strong><br>
                                <?php if (!empty($hours)): ?>
                                    <?php echo $hours[0]['from'] . ' - ' . $hours[0]['to']; ?>
                                <?php else: ?>
                                    Closed
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($service['description'])): ?>
                <div class="jet-meta-item">
                    <div class="jet-meta-label">Description</div>
                    <div class="jet-meta-value"><?php echo wp_trim_words(strip_tags($service['description']), 20); ?></div>
                </div>
                <?php endif; ?>
                
                <div class="jet-service-actions">
                    <?php if ($atts['show_edit'] === 'true'): ?>
                    <a href="#" class="jet-btn-small jet-btn-edit jet-edit-service" data-id="<?php echo $service['id']; ?>"> Edit</a>
                    <?php endif; ?>
                    <?php if ($atts['show_delete'] === 'true'): ?>
                    <a href="#" class="jet-btn-small jet-btn-delete jet-delete-service" data-id="<?php echo $service['id']; ?>"> Delete</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html
        ));
    }
    
    /**
     * Get configurable API base URL
     */
    private function get_api_base_url() {
        $base_url = get_option('jet_api_base_url', '/wp-json/wc/v3');
        return !empty($base_url) ? $base_url : '/wp-json/wc/v3';
    }
    
    /**
     * Get configurable products endpoint
     */
    private function get_products_endpoint() {
        $endpoint = get_option('jet_products_endpoint', 'products');
        return !empty($endpoint) ? $endpoint : 'products';
    }
    
    /**
     * Get configurable JetAppointments meta key
     */
    private function get_jet_meta_key() {
        $meta_key = get_option('jet_appointments_meta_key', 'jet_apb_post_meta');
        return !empty($meta_key) ? $meta_key : 'jet_apb_post_meta';
    }
    
    /**
     * Get configurable relationship meta key
     */
    private function get_relationship_meta_key() {
        $meta_key = get_option('jet_relationship_meta_key', 'relation_6ad702b67a7196b825887ac94787c60d');
        return !empty($meta_key) ? $meta_key : 'relation_6ad702b67a7196b825887ac94787c60d';
    }
    
    /**
     * Get configurable astrologer post type
     */
    private function get_astrologer_post_type() {
        $post_type = get_option('jet_astrologer_post_type', 'astrologers');
        return !empty($post_type) ? $post_type : 'astrologers';
    }
    
    /**
     * Build full API URL for products
     */
    private function build_products_api_url($product_id = '') {
        $base_url = $this->get_api_base_url();
        $endpoint = $this->get_products_endpoint();
        $url = home_url($base_url . '/' . $endpoint);
        
        if (!empty($product_id)) {
            $url .= '/' . intval($product_id);
        }
        
        return $url;
    }
    
    /**
     * Get JetAppointments services (WooCommerce products with jet_apb_post_meta)
     */
    private function get_jet_services($args = array()) {
        $defaults = array(
            'per_page' => 10,
            'provider_id' => ''
        );
        $args = wp_parse_args($args, $defaults);
        
        // Get products via WooCommerce REST API
        $api_url = $this->build_products_api_url();
        $params = array(
            'per_page' => $args['per_page'],
            'meta_key' => $this->get_jet_meta_key()
        );
        
        $response = $this->make_wc_api_request('GET', $api_url, $params);
        
        if (!$response['success']) {
            return array();
        }
        
        $services = array();
        foreach ($response['data'] as $product) {
            $jet_meta = $this->get_jet_meta_from_product($product);
            if ($jet_meta) {
                // Filter by provider if specified
                if (!empty($args['provider_id'])) {
                    $provider_meta = $this->get_provider_meta_from_product($product);
                    if ($provider_meta != $args['provider_id']) {
                        continue;
                    }
                }
                
                $provider_id = $this->get_provider_meta_from_product($product);
                $astrologer_info = null;
                
                if (!empty($provider_id)) {
                    $astrologer_info = $this->get_astrologer_info_by_post_id($provider_id);
                }
                
                $display_price = !empty($product['price']) ? $product['price'] : (isset($jet_meta['meta_settings']['_app_price']) ? strval($jet_meta['meta_settings']['_app_price']) : '0');

                $services[] = array(
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'status' => $product['status'],
                    'price' => $display_price,
                    'description' => $product['description'],
                    'short_description' => $product['short_description'],
                    'permalink' => $product['permalink'],
                    'duration' => isset($jet_meta['custom_schedule']['step_duration']) ? $jet_meta['custom_schedule']['step_duration'] : 3600,
                    'booking_range' => isset($jet_meta['custom_schedule']['appointments_range']['range_num']) ? $jet_meta['custom_schedule']['appointments_range']['range_num'] : 90,
                    'working_hours' => isset($jet_meta['custom_schedule']['working_hours']) ? $jet_meta['custom_schedule']['working_hours'] : array(),
                    'app_price' => isset($jet_meta['meta_settings']['_app_price']) ? $jet_meta['meta_settings']['_app_price'] : 0,
                    'provider_id' => $provider_id,
                    'astrologer_info' => $astrologer_info
                );
            }
        }
        
        return $services;
    }
    
    /**
     * Get single service by ID
     */
    private function get_jet_service_by_id($service_id) {
        $api_url = $this->build_products_api_url($service_id);
        $response = $this->make_wc_api_request('GET', $api_url);
        
        if (!$response['success']) {
            return false;
        }
        
        $product = $response['data'];
        $jet_meta = $this->get_jet_meta_from_product($product);
        
        if (!$jet_meta) {
            return false;
        }
        
        // Prefer app price from meta if Woo price is not set
        $display_price = !empty($product['price']) ? $product['price'] : (isset($jet_meta['meta_settings']['_app_price']) ? strval($jet_meta['meta_settings']['_app_price']) : '0');

        return array(
            'id' => $product['id'],
            'name' => $product['name'],
            'slug' => $product['slug'],
            'status' => $product['status'],
            'price' => $display_price,
            'regular_price' => $display_price,
            'description' => strip_tags($product['description']), // Remove HTML tags for textarea
            'short_description' => $product['short_description'],
            'permalink' => $product['permalink'],
            'duration' => isset($jet_meta['custom_schedule']['step_duration']) ? $jet_meta['custom_schedule']['step_duration'] : 3600,
            'booking_range' => isset($jet_meta['custom_schedule']['appointments_range']['range_num']) ? $jet_meta['custom_schedule']['appointments_range']['range_num'] : 90,
            'working_hours' => isset($jet_meta['custom_schedule']['working_hours']) ? $jet_meta['custom_schedule']['working_hours'] : array(),
            'days_off' => isset($jet_meta['custom_schedule']['days_off']) ? $jet_meta['custom_schedule']['days_off'] : array(),
            'price_type' => isset($jet_meta['meta_settings']['price_type']) ? $jet_meta['meta_settings']['price_type'] : '_app_price',
            'app_price' => isset($jet_meta['meta_settings']['_app_price']) ? $jet_meta['meta_settings']['_app_price'] : 0,
            'provider_id' => $this->get_provider_meta_from_product($product)
        );
    }
    
    /**
     * Extract JetAppointments meta from product
     */
    private function get_jet_meta_from_product($product) {
        if (!isset($product['meta_data']) || !is_array($product['meta_data'])) {
            return false;
        }
        
        foreach ($product['meta_data'] as $meta) {
            if ($meta['key'] === $this->get_jet_meta_key()) {
                return $meta['value'];
            }
        }
        
        return false;
    }
    
    /**
     * Extract provider ID from product meta
     */
    private function get_provider_meta_from_product($product) {
        if (!isset($product['meta_data']) || !is_array($product['meta_data'])) {
            return '';
        }
        
        foreach ($product['meta_data'] as $meta) {
            if ($meta['key'] === $this->get_relationship_meta_key()) {
                return $meta['value'];
            }
        }
        
        return '';
    }
    
    /**
     * Get astrologer post ID by user ID
     */
    private function get_astrologer_post_id_by_user_id($user_id) {
        error_log("JetAppointments Service Manager: Looking for astrologer post for user ID: $user_id");
        
        // Query for astrologer post where the author is the given user ID
        $args = array(
            'post_type' => $this->get_astrologer_post_type(),
            'author' => $user_id,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        
        $astrologer_posts = get_posts($args);
        error_log("JetAppointments Service Manager: Found " . count($astrologer_posts) . " astrologer posts by author for user ID: $user_id");
        
        if (!empty($astrologer_posts)) {
            error_log("JetAppointments Service Manager: Returning astrologer post ID: " . $astrologer_posts[0]);
            return $astrologer_posts[0]; // Return the first astrologer post ID
        }
        
        // If no post found via author, try to find via custom field that might store user ID
        $args = array(
            'post_type' => $this->get_astrologer_post_type(),
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => 'user_id', // Common field name for user ID
                    'value' => $user_id,
                    'compare' => '='
                )
            )
        );
        
        $astrologer_posts = get_posts($args);
        error_log("JetAppointments Service Manager: Found " . count($astrologer_posts) . " astrologer posts by user_id meta field for user ID: $user_id");
        
        if (!empty($astrologer_posts)) {
            error_log("JetAppointments Service Manager: Returning astrologer post ID: " . $astrologer_posts[0]);
            return $astrologer_posts[0];
        }
        
        // If still no post found, try alternative field names
        $alternative_fields = array('user', 'user_id', 'astrologer_user', 'provider_user');
        
        foreach ($alternative_fields as $field) {
            $args = array(
                'post_type' => $this->get_astrologer_post_type(),
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => $field,
                        'value' => $user_id,
                        'compare' => '='
                    )
                )
            );
            
            $astrologer_posts = get_posts($args);
            error_log("JetAppointments Service Manager: Found " . count($astrologer_posts) . " astrologer posts by meta field '$field' for user ID: $user_id");
            
            if (!empty($astrologer_posts)) {
                error_log("JetAppointments Service Manager: Returning astrologer post ID: " . $astrologer_posts[0]);
                return $astrologer_posts[0];
            }
        }
        
        // Let's also check what the actual astrologer post 250 contains
        $post_250 = get_post(250);
        if ($post_250) {
            error_log("JetAppointments Service Manager: Post 250 details - Type: " . $post_250->post_type . ", Author: " . $post_250->post_author . ", Status: " . $post_250->post_status);
            
            // Get all meta fields for post 250
            $meta_fields = get_post_meta(250);
            error_log("JetAppointments Service Manager: Post 250 meta fields: " . print_r($meta_fields, true));
        }
        
        // Also try to get all astrologer posts to see what's available
        $all_astrologers = get_posts(array(
            'post_type' => $this->get_astrologer_post_type(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        if (!empty($all_astrologers)) {
            error_log("JetAppointments Service Manager: Found " . count($all_astrologers) . " astrologer posts: " . implode(', ', $all_astrologers));
            
            // Check each astrologer post for user ID connections
            foreach ($all_astrologers as $astrologer_id) {
                $post = get_post($astrologer_id);
                $meta_fields = get_post_meta($astrologer_id);
                error_log("JetAppointments Service Manager: Astrologer post $astrologer_id - Author: " . $post->post_author . ", Meta: " . print_r($meta_fields, true));
            }
        } else {
            error_log("JetAppointments Service Manager: No astrologer posts found at all");
        }
        
        return false; // No astrologer post found
    }
    
    /**
     * Manually link user to astrologer post (for cases where admin creates astrologer post)
     */
    private function link_user_to_astrologer_post($user_id, $astrologer_post_id) {
        // Update the astrologer post to set the user as the author
        $update_args = array(
            'ID' => $astrologer_post_id,
            'post_author' => $user_id
        );
        
        $result = wp_update_post($update_args);
        
        if ($result) {
            error_log("JetAppointments Service Manager: Successfully linked user ID $user_id to astrologer post ID $astrologer_post_id");
            return true;
        } else {
            error_log("JetAppointments Service Manager: Failed to link user ID $user_id to astrologer post ID $astrologer_post_id");
            return false;
        }
    }
    
    /**
     * Get astrologer information by post ID
     */
    private function get_astrologer_info_by_post_id($post_id) {
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== $this->get_astrologer_post_type()) {
            return false;
        }
        
        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'name' => $post->post_title,
            'user_id' => $post->post_author,
            'permalink' => get_permalink($post->ID)
        );
    }
    
    /**
     * Sync astrologer-product relationship bidirectionally
     * Updates the astrologer post to include this product in its relation field
     */
    private function sync_astrologer_product_relationship($astrologer_post_id, $product_id) {
        $meta_key = $this->get_relationship_meta_key();
        // Fetch all meta rows (JetFormBuilder checkbox commonly stores one row per selected value)
        $all_rows = get_post_meta($astrologer_post_id, $meta_key, false);

        // If there is exactly one row and it is itself an array, we are in "single meta row with array" mode.
        // Migrate that to multi-row storage to stay compatible with JetFormBuilder checkbox field.
        if (count($all_rows) === 1 && is_array($all_rows[0])) {
            $array_values = array_filter(array_map('intval', $all_rows[0]));
            // Remove the single array row
            delete_post_meta($astrologer_post_id, $meta_key);
            // Re-insert each value as separate meta rows (deduped)
            $inserted = array();
            foreach ($array_values as $val) {
                if ($val && !in_array($val, $inserted, true)) {
                    add_post_meta($astrologer_post_id, $meta_key, $val, false);
                    $inserted[] = $val;
                }
            }
            // Refresh $all_rows after migration
            $all_rows = get_post_meta($astrologer_post_id, $meta_key, false);
        }

        // If there are no rows at all, just add the current product id as the first row
        if (empty($all_rows)) {
            add_post_meta($astrologer_post_id, $meta_key, intval($product_id), false);
            error_log("JetAppointments Service Manager: Initialized relations with product $product_id for astrologer $astrologer_post_id (multi-row mode)");
            return true;
        }

        // Normalize current scalar rows to integers and de-duplicate
        $current_values = array();
        foreach ($all_rows as $row) {
            if (is_array($row)) {
                // Safety: if any array slipped in, merge its ints
                foreach ($row as $sub) {
                    $ival = intval($sub);
                    if ($ival && !in_array($ival, $current_values, true)) {
                        $current_values[] = $ival;
                    }
                }
            } else {
                $ival = intval($row);
                if ($ival && !in_array($ival, $current_values, true)) {
                    $current_values[] = $ival;
                }
            }
        }

        // Append new value only if not present; use add_post_meta to preserve multi-row storage
        if (!in_array(intval($product_id), $current_values, true)) {
            add_post_meta($astrologer_post_id, $meta_key, intval($product_id), false);
            $current_values[] = intval($product_id);
            error_log("JetAppointments Service Manager: Appended product $product_id to astrologer $astrologer_post_id. Current relations: " . implode(', ', $current_values));
            return true;
        }

        error_log("JetAppointments Service Manager: Product $product_id already linked to astrologer $astrologer_post_id (no changes)");
        return true;
    }
    
    /**
     * Create WooCommerce product via REST API
     */
    private function create_woocommerce_product($product_data) {
        $api_url = $this->build_products_api_url();
        $response = $this->make_wc_api_request('POST', $api_url, $product_data);
        
        if ($response['success']) {
            return array(
                'success' => true,
                'product_id' => $response['data']['id'],
                'permalink' => $response['data']['permalink']
            );
        } else {
            return array(
                'success' => false,
                'message' => $response['message']
            );
        }
    }
    
    /**
     * Update WooCommerce product via REST API
     */
    private function update_woocommerce_product($product_id, $update_data) {
        $api_url = $this->build_products_api_url($product_id);
        $response = $this->make_wc_api_request('PUT', $api_url, $update_data);
        
        if ($response['success']) {
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'message' => $response['message']
            );
        }
    }

    /**
     * Ensure JetAppointments price is synced to WooCommerce meta and caches are cleared
     */
    private function force_price_sync($product_id, $app_price) {
        try {
            update_post_meta($product_id, '_regular_price', $app_price);
            update_post_meta($product_id, '_price', $app_price);
            update_post_meta($product_id, '_sale_price', '');
            update_post_meta($product_id, '_app_price', $app_price);

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            wp_cache_delete($product_id, 'posts');
            wp_cache_delete($product_id, 'post_meta');
            return true;
        } catch (Exception $e) {
            error_log('JetAppointments Service Manager: force_price_sync failed for product ' . $product_id . ' - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hook to sync JetAppointments price to WooCommerce when product is created/updated
     */
    public function sync_jetappointment_price_to_wc($product_id) {
        $jet_meta = get_post_meta($product_id, 'jet_apb_post_meta', true);
        if (is_array($jet_meta) && isset($jet_meta['meta_settings']['_app_price'])) {
            $app_price = floatval($jet_meta['meta_settings']['_app_price']);
            if ($app_price > 0) {
                update_post_meta($product_id, '_regular_price', $app_price);
                update_post_meta($product_id, '_price', $app_price);
            }
        }
    }

    /**
     * Ensure checkout shows JetAppointments price if present
     */
    public function get_jetappointment_price($price, $product) {
        $jet_meta = get_post_meta($product->get_id(), 'jet_apb_post_meta', true);
        if (is_array($jet_meta) && isset($jet_meta['meta_settings']['_app_price'])) {
            $app_price = floatval($jet_meta['meta_settings']['_app_price']);
            if ($app_price > 0) {
                return $app_price;
            }
        }
        $app_price_meta = get_post_meta($product->get_id(), '_app_price', true);
        if (!empty($app_price_meta) && floatval($app_price_meta) > 0) {
            return floatval($app_price_meta);
        }
        return $price;
    }

    /**
     * Optional: Debug current pricing state for a product
     */
    public function debug_product_pricing($product_id) {
        $wc_price = get_post_meta($product_id, '_price', true);
        $wc_regular_price = get_post_meta($product_id, '_regular_price', true);
        $app_price = get_post_meta($product_id, '_app_price', true);
        $jet_meta = get_post_meta($product_id, 'jet_apb_post_meta', true);
        $debug_info = array(
            'product_id' => $product_id,
            'wc_price' => $wc_price,
            'wc_regular_price' => $wc_regular_price,
            'app_price_meta' => $app_price,
            'jet_meta_price' => is_array($jet_meta) && isset($jet_meta['meta_settings']['_app_price']) ? $jet_meta['meta_settings']['_app_price'] : 'not_found'
        );
        error_log('JetAppointments Service Manager Debug: ' . print_r($debug_info, true));
        return $debug_info;
    }
    
    /**
     * Delete WooCommerce product via REST API
     */
    private function delete_woocommerce_product($product_id) {
        $api_url = $this->build_products_api_url($product_id);
        $response = $this->make_wc_api_request('DELETE', $api_url, array('force' => true));
        
        if ($response['success']) {
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'message' => $response['message']
            );
        }
    }
    
    /**
     * Make WooCommerce REST API request
     */
    private function make_wc_api_request($method, $url, $data = array()) {
        // Get WooCommerce API credentials from WordPress options or define them here
        $consumer_key = defined('WC_CONSUMER_KEY') ? WC_CONSUMER_KEY : get_option('wc_api_consumer_key', '');
        $consumer_secret = defined('WC_CONSUMER_SECRET') ? WC_CONSUMER_SECRET : get_option('wc_api_consumer_secret', '');
        
        if (empty($consumer_key) || empty($consumer_secret)) {
            return array(
                'success' => false,
                'message' => 'WooCommerce API credentials not configured. Please set WC_CONSUMER_KEY and WC_CONSUMER_SECRET constants or options.'
            );
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        } elseif (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($response_body, true);
        
        if (in_array($response_code, array(200, 201))) {
            return array(
                'success' => true,
                'data' => $decoded_response
            );
        } else {
            $error_message = 'HTTP ' . $response_code;
            if (isset($decoded_response['message'])) {
                $error_message .= ': ' . $decoded_response['message'];
            }
            
            return array(
                'success' => false,
                'message' => $error_message,
                'response_code' => $response_code,
                'response_body' => $response_body
            );
        }
    }
}

// Initialize the plugin
new JetAppointmentsServiceManager();

/**
 * Installation function - Run when plugin is activated
 */
register_activation_hook(__FILE__, 'jet_service_manager_install');

function jet_service_manager_install() {
    // Create options for API credentials if they don't exist
    if (!get_option('wc_api_consumer_key')) {
        add_option('wc_api_consumer_key', '');
    }
    if (!get_option('wc_api_consumer_secret')) {
        add_option('wc_api_consumer_secret', '');
    }
    
    // Create options for configurable API endpoints and relationship names
    if (!get_option('jet_api_base_url')) {
        add_option('jet_api_base_url', '/wp-json/wc/v3');
    }
    if (!get_option('jet_products_endpoint')) {
        add_option('jet_products_endpoint', 'products');
    }
    if (!get_option('jet_appointments_meta_key')) {
        add_option('jet_appointments_meta_key', 'jet_apb_post_meta');
    }
    if (!get_option('jet_relationship_meta_key')) {
        add_option('jet_relationship_meta_key', 'relation_6ad702b67a7196b825887ac94787c60d');
    }
    if (!get_option('jet_astrologer_post_type')) {
        add_option('jet_astrologer_post_type', 'astrologers');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Settings page for API credentials
 */
add_action('admin_menu', 'jet_service_manager_admin_menu');

function jet_service_manager_admin_menu() {
    add_options_page(
        'JetAppointments Service Manager Settings',
        'Jet Service Manager',
        'manage_options',
        'jet-service-manager',
        'jet_service_manager_settings_page'
    );
}

function jet_service_manager_settings_page() {
    if (isset($_POST['submit'])) {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['jet_settings_nonce'], 'jet_service_manager_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
        } else {
            // Update WooCommerce API credentials
            update_option('wc_api_consumer_key', sanitize_text_field($_POST['consumer_key']));
            update_option('wc_api_consumer_secret', sanitize_text_field($_POST['consumer_secret']));
            
            // Update configurable API endpoints and relationship names
            update_option('jet_api_base_url', sanitize_text_field($_POST['api_base_url']));
            update_option('jet_products_endpoint', sanitize_text_field($_POST['products_endpoint']));
            update_option('jet_appointments_meta_key', sanitize_text_field($_POST['appointments_meta_key']));
            update_option('jet_relationship_meta_key', sanitize_text_field($_POST['relationship_meta_key']));
            update_option('jet_astrologer_post_type', sanitize_text_field($_POST['astrologer_post_type']));
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
    }
    
    // Get current option values
    $consumer_key = get_option('wc_api_consumer_key', '');
    $consumer_secret = get_option('wc_api_consumer_secret', '');
    $api_base_url = get_option('jet_api_base_url', '/wp-json/wc/v3');
    $products_endpoint = get_option('jet_products_endpoint', 'products');
    $appointments_meta_key = get_option('jet_appointments_meta_key', 'jet_apb_post_meta');
    $relationship_meta_key = get_option('jet_relationship_meta_key', 'relation_6ad702b67a7196b825887ac94787c60d');
    $astrologer_post_type = get_option('jet_astrologer_post_type', 'astrologers');
    ?>
    <div class="wrap">
        <h1>JetAppointments Service Manager Settings</h1>
        
        <div class="card" style="max-width: 600px;">
            <h2>Plugin Configuration</h2>
            <p>Configure your WooCommerce API credentials and plugin settings.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('jet_service_manager_settings', 'jet_settings_nonce'); ?>
                
                <h3>WooCommerce API Credentials</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Consumer Key</th>
                        <td>
                            <input type="text" name="consumer_key" value="<?php echo esc_attr($consumer_key); ?>" class="regular-text" />
                            <p class="description">WooCommerce API Consumer Key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Consumer Secret</th>
                        <td>
                            <input type="text" name="consumer_secret" value="<?php echo esc_attr($consumer_secret); ?>" class="regular-text" />
                            <p class="description">WooCommerce API Consumer Secret</p>
                        </td>
                    </tr>
                </table>
                
                <h3>API Configuration</h3>
                <p>Configure API endpoints and relationship names for cross-site compatibility.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">API Base URL</th>
                        <td>
                            <input type="text" name="api_base_url" value="<?php echo esc_attr($api_base_url); ?>" class="regular-text" />
                            <p class="description">Base URL for WooCommerce REST API (default: /wp-json/wc/v3)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Products Endpoint</th>
                        <td>
                            <input type="text" name="products_endpoint" value="<?php echo esc_attr($products_endpoint); ?>" class="regular-text" />
                            <p class="description">Products endpoint name (default: products)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">JetAppointments Meta Key</th>
                        <td>
                            <input type="text" name="appointments_meta_key" value="<?php echo esc_attr($appointments_meta_key); ?>" class="regular-text" />
                            <p class="description">Meta key for JetAppointments service data (default: jet_apb_post_meta)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Relationship Meta Key</th>
                        <td>
                            <input type="text" name="relationship_meta_key" value="<?php echo esc_attr($relationship_meta_key); ?>" class="regular-text" />
                            <p class="description">Meta key for astrologer-product relationships (default: relation_6ad702b67a7196b825887ac94787c60d)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Astrologer Post Type</th>
                        <td>
                            <input type="text" name="astrologer_post_type" value="<?php echo esc_attr($astrologer_post_type); ?>" class="regular-text" />
                            <p class="description">Custom post type for astrologers (default: astrologers)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save API Configuration'); ?>
            </form>
            
            <div class="notice notice-info" style="margin-top: 20px;">
                <h4>🔧 Current Configuration Preview</h4>
                <p><strong>Full API URL:</strong> <code><?php echo esc_html(home_url($api_base_url . '/' . $products_endpoint)); ?></code></p>
                <p><strong>Meta Keys:</strong> JetAppointments: <code><?php echo esc_html($appointments_meta_key); ?></code>, Relationship: <code><?php echo esc_html($relationship_meta_key); ?></code></p>
                <p><strong>Post Type:</strong> <code><?php echo esc_html($astrologer_post_type); ?></code></p>
            </div>
            
            <h3>How to get API credentials:</h3>
            <ol>
                <li>Go to <strong>WooCommerce → Settings → Advanced → REST API</strong></li>
                <li>Click <strong>Add Key</strong></li>
                <li>Set Description: "JetAppointments Service Manager"</li>
                <li>Set User: Choose an admin user</li>
                <li>Set Permissions: <strong>Read/Write</strong></li>
                <li>Click <strong>Generate API Key</strong></li>
                <li>Copy the Consumer Key and Consumer Secret here</li>
            </ol>
        </div>
        
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>Available Shortcodes</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Description</th>
                        <th>Attributes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[jet_service_form]</code></td>
                        <td>Create new service form</td>
                        <td>provider_id, show_title</td>
                    </tr>
                    <tr>
                        <td><code>[jet_service_list]</code></td>
                        <td>List all services</td>
                        <td>per_page, show_edit, show_delete, provider_id</td>
                    </tr>
                    <tr>
                        <td><code>[jet_service_edit id="123"]</code></td>
                        <td>Edit specific service</td>
                        <td>id (required)</td>
                    </tr>
                </tbody>
            </tbody>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>📖 API Configuration Guide</h2>
            
            <h3>Cross-Site Deployment</h3>
            <p>The API Configuration section above allows you to customize endpoints and relationship names for different WordPress installations. This is particularly useful when:</p>
            <ul>
                <li><strong>Testing across multiple sites</strong> - Different sites may have different API structures</li>
                <li><strong>Custom JetAppointments setups</strong> - Sites with modified meta keys or post types</li>
                <li><strong>Multi-environment deployments</strong> - Development, staging, and production environments</li>
            </ul>
            
            <h3>Configuration Options Explained</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Setting</th>
                        <th>Purpose</th>
                        <th>Example Values</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>API Base URL</strong></td>
                        <td>WooCommerce REST API base path</td>
                        <td><code>/wp-json/wc/v3</code>, <code>/wp-json/wc/v2</code></td>
                    </tr>
                    <tr>
                        <td><strong>Products Endpoint</strong></td>
                        <td>Products endpoint name</td>
                        <td><code>products</code>, <code>services</code></td>
                    </tr>
                    <tr>
                        <td><strong>JetAppointments Meta Key</strong></td>
                        <td>Meta key storing service configuration</td>
                        <td><code>jet_apb_post_meta</code>, <code>custom_service_meta</code></td>
                    </tr>
                    <tr>
                        <td><strong>Relationship Meta Key</strong></td>
                        <td>Meta key linking astrologers to services</td>
                        <td><code>relation_6ad702b67a7196b825887ac94787c60d</code>, <code>astrologer_services</code></td>
                    </tr>
                    <tr>
                        <td><strong>Astrologer Post Type</strong></td>
                        <td>Custom post type for astrologer profiles</td>
                        <td><code>astrologers</code>, <code>providers</code>, <code>consultants</code></td>
                    </tr>
                </tbody>
            </table>
            
            <h3>⚠️ Important Notes</h3>
            <div class="notice notice-warning">
                <ul>
                    <li><strong>Backward Compatibility:</strong> All settings have default values matching the original hardcoded values</li>
                    <li><strong>Testing Required:</strong> After changing settings, test service creation/editing to ensure compatibility</li>
                    <li><strong>Backup Recommended:</strong> Always backup your database before making configuration changes</li>
                    <li><strong>Site-Specific:</strong> These settings are stored per WordPress installation</li>
                </ul>
            </div>
            
            <h3>🚀 Quick Setup for New Sites</h3>
            <ol>
                <li><strong>Install the plugin</strong> on your target WordPress site</li>
                <li><strong>Configure WooCommerce API credentials</strong> (section above)</li>
                <li><strong>Identify your site's specific values:</strong>
                    <ul>
                        <li>Check your JetAppointments plugin version and meta keys</li>
                        <li>Verify your custom post type names</li>
                        <li>Test your WooCommerce API endpoints</li>
                    </ul>
                </li>
                <li><strong>Update the API Configuration</strong> with your site-specific values</li>
                <li><strong>Test service creation</strong> using the shortcodes</li>
            </ol>
        </div>
    </div>
    <?php
}

/**
 * Usage Examples:
 * 
 * 1. Create Service Form:
 * [jet_service_form provider_id="5209"]
 * 
 * 2. List Services:
 * [jet_service_list per_page="20" provider_id="5209"]
 * 
 * 3. Edit Service:
 * [jet_service_edit id="5183"]
 * 
 * 4. List Services (Admin View):
 * [jet_service_list show_edit="true" show_delete="true"]
 */
