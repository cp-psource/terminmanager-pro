AppointmentsAdmin = window.AppointmentsAdmin || {};
( function( global, strings, $ ) {
    "use strict";

    /**
     * Constructor
     *
     * @param options
     * @returns {AppointmentsList}
     * @constructor
     */
    function AppointmentsList( options ) {
        // Set default options array
        var defaults = {
            servicesPrice: []
        };
        this.options = $.extend( {}, defaults, options );

        this.isEditing = false;

        // Export button
        this.$exportButton = $('.app-export_trigger');

        // Add new appointment button
        this.$addNew = $(".add-new-h2");
        this.$addNewSpinner = $(".add-new-waiting");

        // Edit single appointment
        this.$editAppointment = $(".app-inline-edit");

        // App list table
        this.$table = $('table.appointments');

        // Confirm removal of selected appointments
        $("#delete_removed").on( "click", function(e) {
            return confirm(strings.deleteRecordsConfirm);
        });

        // Show inline editor when add new button is clicked
        this.$addNew.on( "click", function() {
            this.$addNewSpinner.show();
            this.removeActiveEditorForms();
            this.showEditorForm( 0, function( result ) {
                this.$table.prepend(result);
                this.$addNewSpinner.hide();
            });
        }.bind( this ) );

        // Show inline editor when editing a single appointment
        this.$editAppointment.on( "click", function(e) {
            var appId = $(e.target).data( 'app-id' );
            var row = $('#app-' + appId );
            var $spinner = row.find(".waiting");

            $spinner.show();

            this.removeActiveEditorForms();

            this.showEditorForm( appId, function( result ) {
                $spinner.hide();
                row.hide();
                row.after( result );
            });
        }.bind(this));

        // Cancel edition in inline editor
        this.$table.on( 'click', '.cancel', this.removeActiveEditorForms.bind(this) );

        // Change service price as selection changes
        this.$table.on( 'change', 'select[name="service"]', function(e) {
            var target = $(e.target);
            var value = target.val();
            if ( this.options.servicesPrice[ value ] ) {
                target.parents(".inline-edit-col")
                    .find( 'input[name="price"]' )
                    .val( this.options.servicesPrice[ value ] );
            }
        }.bind(this));

        this.$table.on( 'click', '.save', this.saveEditor.bind(this) );
        
        this.$table.on( 'change', 'select[name=worker]', this.fetchWorkerHours.bind(this) );

        // @TODO Refactor
        this.$exportButton.on( "click", function(e) {
            var button = $(e.target),
                $form = button.closest("form"),
                checkedApps = $(".column-delete.app-check-column :checked"),
                type = $form.find("#app-export_type");

            if (button.is("#app-export-selected") && checkedApps.length) {
                checkedApps.each(function () {
                    $form.append("<input type='hidden' name='app[]' value='" + $(this).val() + "' />");
                });
                type.val("selected");
                return true;
            } else if (button.is("#app-export-type")) {
                $form.append("<input type='hidden' name='status' value='" + button.attr("data-type") + "' />");
                type.val("type");
                return true;
            } else if (button.is("#app-export-all")) {
                type.val("all");
                return true;
            }
            return false;
        });

        $(".app-change-status-btn").on( "click", function(e){
            var button = $(this);
            var selection = $("th.app-check-column input:checkbox:checked");
            // var data = { 'app[]' : []};
            selection.each(function() {
                // data['app[]'].push($(this).val());
                button.after('<input type="hidden" name="app[]" value="'+$(this).val()+'"/>');
            });

            return true;
        });

        $(".info-button").on( "click", function(){
            $(".status-description").toggle('fast');
        });

        function toggle_selected_export () {
            var $sel = $("#the-list .check-column :checked");
            if ($sel.length) $("#app-export-selected").show();
            else $("#app-export-selected").hide();
        }

        $(document).on("change", ".check-column input, .app-column-cb input", toggle_selected_export);
        $(toggle_selected_export);


        return this;
    }

    /**
     * Hide all active inline editors and show all rows in the table
     */
    AppointmentsList.prototype.removeActiveEditorForms = function() {
        this.$table.find( '.inline-edit-row' ).hide();
        $(".app-tr").show();
    };


    /**
     * Show the inline editor form
     *
     * @param app_id Appointment ID
     * @param callback Callback function to execute after success
     */
    AppointmentsList.prototype.showEditorForm = function( app_id, callback ) {
        var data = {
            action: 'inline_edit',
            col_len: this.options.columns,
            app_id: app_id,
            nonce: this.options.nonces.addNew,
            columns: this.options.columns
        };

        return $.post(ajaxurl, data, function (response) {
                if ( response && response.error ) {
                    alert(response.error);
                }
                else if (response && typeof callback === 'function' ) {
                    callback.call( this, [ response.result ] );
                    this.initDatepicker();
                }
                else {
                    alert( strings.unexpectedError );
                }
            }.bind(this),
            'json'
        );
    };

    /**
     * Initializes datepickers
     */
    AppointmentsList.prototype.initDatepicker = function() {
        $( '.datepicker' ).datepicker({
            dateFormat: 'yy-mm-dd',
            firstDay: AppointmentsDateSettings.weekStart
        });
    };
    
    /**
    * Fetches the working hours of the selected provider with ajax
    */
    AppointmentsList.prototype.fetchWorkerHours = function(e) {
        
        var $select             = $( e.target ),
            $worker_id          = $select.val(),            
            $parent             = $select.parents( ".inline-edit-row" ),
            $app_id             = $parent.find( "select[name=service]" ).val(),
            $location           = $parent.find( "select[name=location]" ),
            $slots_list         = $parent.find( "select[name=time]" ),
            $selected_slot      = $slots_list.val(),
            $unknown_slot       = $slots_list.children('option:first'),
            $spinner            = $parent.find( '.waiting' ),
            data                = {};        

        $spinner.show();

        /**
        * Set up ajax data
        */
        data.nonce          = this.options.nonces.editApp;
        data.action         = 'inline_fetch_worker_slots';
        data.worker_id      = $worker_id;
        data.app_id         = $app_id;
        data.selected_slot  = $selected_slot;

        // @TODO: Check if date is set and include that too in ajax data

        if ( typeof( $location ) != "undefined" ) {
            data.location_id         = $location.val();
        }

        /**
        * Handle ajax response
        */
        $.post(ajaxurl, data, function(response) {

            $spinner.hide();

            // When receiving an error message
            if ( response && response.error ){
                
                var error_msg = $('<div />',{
                    'class' : 'error'
                }).html( response.error );

                $select.after( error_msg );
                error_msg.delay(10000).fadeOut('slow');

                return;

            } else if (response) {
                // Received the new time slots for worker
                 var slots = JSON.parse( response.message );

                // Empty the old slots list and add the unknown option
                $slots_list.empty().append( '<option value="' + $unknown_slot.val() + '">' + $unknown_slot.text() + '</option>' );

                // Add the new timeslots
                for ( var key in slots ) {
                    $slots_list.append( slots[key] );
                }
            } else {
                alert( strings.unexpectedError );
                return;
            }


        }.bind(this),'json');
    }

    AppointmentsList.prototype.saveEditor = function(e) {
        var $button = $(e.target);
        var target = $button.parents(".inline-edit-row");
        var $spinner = target.find(".waiting");

        $spinner.show();

        var fields = [
            'user',
            'cname',
            'email',
            'phone',
            'address',
            'city',
            'service',
            'worker',
            'price',
            'date',
            'time',
            'note',
            'status'
        ];

        var data = {};
        $.map( fields, function( fieldName ) {
            if ( 'cname' === fieldName ) {
                data.name = target.find( '[name="' + fieldName + '"]').val();
            }
            else {
                data[fieldName] = target.find( '[name="' + fieldName + '"]').val();
            }
        });

        var app_id = $button.data('app-id');
        var cancel_button = target.find('.cancel');
        data.app_id = app_id;
        data.resend = target.find('input[name="resend"]').is(':checked') ? 1 : 0;
        data.nonce = this.options.nonces.editApp;
        data.action = 'inline_edit_save';
        $(document).trigger('app-appointment-inline_edit-save_data', [data, target]);

        $.post(ajaxurl, data, function(response) {
            $spinner.hide();
            if ( response && response.error ){
                target.find(".error").html(response.error).show().delay(10000).fadeOut('slow');
                return;
            } else if (response) {
                target.find(".error").html(response.message).show().delay(10000).fadeOut('slow');
            } else {
                alert( strings.unexpectedError );
                return;
            }

            if (!(app_id && parseInt(app_id, 10)) && response && response.app_id) {
                location.reload();
            }
            else {
                // this.removeActiveEditorForms();
            }

            if ( response.reload ){
                location.reload();
            }
            cancel_button[0].innerHTML = 'Close';
        }.bind(this),'json');
    };

    /**
     * Helper constructor function
     *
     * @param options
     * @returns {AppointmentsList}
     */
    global.appointmentsList = function( options ) {
        return new AppointmentsList( options );
    };

})( AppointmentsAdmin, Appi18n, jQuery );
