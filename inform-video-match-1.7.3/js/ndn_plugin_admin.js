// Javascript for admin pages
( function($) {
  'use strict';

// Media Wizard
  jQuery( document ).ready( function() { 

    // Initialize Google Analytics
    initializeGA();
    // Event Listeners for plugin actions
    ndnChangedResponsiveCheckbox();
    jQuery( '.ndn-responsive-checkbox' ).change( ndnChangedResponsiveCheckbox );
    jQuery( '.ndn-featured-image-checkbox').change( ndnChangedFeaturedImageCheckbox );

    // Login Toggle
    jQuery( '.ndn-login-form-type' ).change( ndnChangeLoginForm );

    // Input Validators
    jQuery( '#ndn-plugin-first-time-login' ).submit( ndnValidateCompleteLoginForm );
    jQuery( 'input[name="ndn-plugin-default-tracking-group"]' ).keyup( ndnValidateInput );
    jQuery( 'input[name="ndn-plugin-default-width"]' ).keyup( ndnValidateInput );

    // Add Link Attributes
    jQuery( '.ndn-notify-credentials' ).attr( 'analytics-category', 'WPSettings' );
    jQuery( '.ndn-notify-credentials' ).attr( 'analytics-label', 'SettingsPage' );
    jQuery( '.ndn-notify-settings' ).attr( 'analytics-category', 'WPSettings' );
    jQuery( '.ndn-notify-settings' ).attr( 'analytics-label', 'SettingsPage' );

    // Google Analytics
    jQuery( '.ndn-email-help' ).on( 'click', ndnGAClickEvent );
    jQuery( 'form[name="ndn-plugin-login-form"]' ).on( 'submit', ndnGASubmitEvent );
    jQuery( 'form[name="ndn-plugin-default-settings-form"]' ).on( 'submit', ndnGASubmitEvent );
    jQuery( '.ndn-notify-credentials' ).on( 'click', ndnGAClickEvent );
    jQuery( '.ndn-notify-settings' ).on( 'click', ndnGAClickEvent );
    jQuery( '.ndn-plugin-wiz-button' ).on('click', ndnGASendUrl);
    jQuery( '#ndn-plugin-default-settings-form' ).on('submit', checkDefaultClass);

    // Register functions
    window.addEventListener( 'videoSelected' , assignFeaturedImage, false );

  
  //Onclick open the advance popup
  jQuery('#ndn-advance-search-button').click(function() {
    jQuery('#ndn-advance-search').toggle('slow', function() {  
      document.getElementById('ndn-advance-search').style.display = "block";
    });
  });
  jQuery('#ndn-pop-up-close-button').click(function(){
    jQuery('#ndn-advance-search').hide();
  });
  /**
  Search enhancement functions in search result page
  Sort By Video Type
  */
  jQuery('#videotypelist').on('change', function() {
    jQuery("#sort-by-video-type").val(this.value);
    jQuery('#ndn-search-submit').click();
  });
  /**
  Sort By Upload date
  */
  jQuery('#ndnVideoUploadDate').on('change', function() {
    jQuery("#sort-by-upload-date").val(this.value);
    jQuery('#ndn-search-submit').click();
  });
  /**
  Advance Search
  */
  jQuery('#ndn-advance-submit').on('click', function() {
    var minDate = jQuery("#ndn-custom-date-start").val();
    var maxDate = jQuery("#ndn-custom-date-end").val();
    var videoDuration = jQuery("#ndn-video-duration-list").val();
    jQuery("#sort-by-min-date").val(minDate);
    jQuery("#sort-by-max-date").val(maxDate);
    jQuery("#sort-by-video-duration").val(videoDuration);
    $('#ndn-search-submit').click();
  });
  });

  /**
   * On change responsive checkbox
   */
  function ndnChangedResponsiveCheckbox() {
    if ($( '.ndn-responsive-checkbox' ).is( ':checked' )) {
      $( 'input[name=ndn-plugin-default-width]' ).prop( 'disabled', true );
      $( '.ndn-default-width-disabled' ).prop( 'disabled', false );
      $( '.ndn-responsive-checkbox-disabled' ).prop( 'disabled', true );
    } else {
      $( 'input[name=ndn-plugin-default-width]' ).prop( 'disabled', false );
      $( '.ndn-default-width-disabled' ).prop( 'disabled', true );
      $( '.ndn-responsive-checkbox-disabled' ).prop( 'disabled', false );
    }
  }
  /**
  * Check the default class as not ndn_embed
  */
  function checkDefaultClass(){
    if($("#ndn-plugin-default-div-class").val() == 'ndn_embed'){
    $("#ndn-denied-message").text('ndn_embed not allowed. Phrase is reserved for video embed.');
    return false;
   }
  }
  /**
   * On change featured image checkbox
   */
  function ndnChangedFeaturedImageCheckbox() {
    if ($( '.ndn-featured-image-checkbox' ).is( ':checked' )) {
      $( '.ndn-featured-image-checkbox-disabled' ).prop( 'disabled', true );
    } else {
      $( '.ndn-featured-image-checkbox-disabled' ).prop( 'disabled', false );
    }
  }

  /**
   * Change between first time login and returning login
   */
  function ndnChangeLoginForm() {
    $( '#ndn-plugin-first-time-login' ).toggle();
    $( '#ndn-plugin-returning-login' ).toggle();
  }

  /**
   * Validate Completed Login Form
   * @return {bool} Returns boolean whether the form was completed or not
   */
  function ndnValidateCompleteLoginForm() {
    /**
     * Highlight the field that is not complete
     */
    function ndnHighlightIncompleteFields() {
      $('#ndn-plugin-first-time-login input').each(function() {
        if ( !$(this).val() ) {
          $(this).addClass( 'ndn-input-invalid' );
        } else {
          $( this ).removeClass( 'ndn-input-invalid' );
        }
      });
    }

    if ( $.trim( $( '#ndn-plugin-login-username' ).val() ) === '' || $.trim( $( '#ndn-plugin-login-password' ).val() ) === '' || $.trim( $( '#ndn-plugin-login-company-name' ).val() ) === '' || $.trim( $( '#ndn-plugin-login-contact-name' ).val() ) === '' || $.trim( $( '#ndn-plugin-login-contact-email' ).val() ) === '' ) {
      if ( event ) {
        event.preventDefault();
      }

      ndnHighlightIncompleteFields();
      return false;
    }
  }

  /**
   * Validates input with regular expressions
   */
  function ndnValidateInput() {
    /*jshint validthis:true */
    var self = this,
      name = $( self ).attr( 'name' ),
      invalidElement = $( self ).siblings( '.invalid-input' );

    validateTrackingGroup( name, invalidElement );
  }

  /**
   * Validates the tracking group input
   * @param  {string} inputName       Name of the input
   * @param  {element} invalidDiv   Element of the invalid message div class
   */
  function validateTrackingGroup( inputName, invalidDiv ) {
    var regex = new RegExp( /^[0-9]*$/ ),
      value = $( 'input[name="' + inputName + '"]' ).val();
    if (!regex.test( value )) {
      invalidDiv.show();
      $( '.submit-settings-form' ).attr( 'disabled','disabled' );
    } else {
      invalidDiv.hide();
      $( '.submit-settings-form' ).removeAttr( 'disabled' );
    }
  }

  /**
   * Intialize Google Analytics
   */
  function initializeGA() {
     /* jshint ignore:start */
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

    ga('create', 'UA-65160109-1', 'auto');

     /* jshint ignore:end */
  }

  /**
   * Attach a link click event listener for GA
   * @param  {element} element (optional) element object
   */
  function ndnGAClickEvent( element ) {
    var link,
      href,
      target,
      category,
      label,
      value;

    /*jshint validthis:true */
    if (!this) {
      link = $( element );
    } else {
      link = $( this );
    }

    href = link.attr( 'href' );
    target = link.attr( 'target' );
    category = link.attr( 'analytics-category' );
    label = link.attr( 'analytics-label' );

    ga('send', 'event', category, 'click', label);
  }

  /**
   * Send GA url
   * @param  {element} element (optional) element object
   */
  function ndnGASendUrl( element ) {
    var link,
      href,
      target,
      category,
      label,
      baseUrl;

    /*jshint validthis:true */
    if (!this) {
      link = $( element );
    } else {
      link = $( this );
    }

    href = link.attr( 'href' );
    target = link.attr( 'target' );
    category = link.attr( 'analytics-category' );
    baseUrl = window.location.origin ? window.location.origin + '/' : window.location.protocol + '/' + window.location.host + '/';
    label = link.attr( 'analytics-label' ) + '_' + baseUrl;

    ga('send', 'event', category, 'click', label);
  }

  /**
   * Attach a form submit event listener for GA
   * @param  {object} event event object
   */
  function ndnGASubmitEvent() {
    var jqForm,
      category,
      label;
    /*jshint validthis:true */
    jqForm = $( this );
    category = jqForm.attr( 'analytics-category' );
    label = jqForm.attr( 'analytics-label' );

    ga('send', 'event', category, 'submit', label);
  }

  /**
   * assign featured image to Post
   * @param  {String} url link to the thumbnail
   */
  function assignFeaturedImage ( event ) {
    // Create data object
    var data = {
      action: 'set_featured_image',
      url: event.detail.src,
      description: event.detail.alt,
      security: event.detail.security,
      postID: NDNAjax.postID,
    };

    
    /**
     * After image has been assigned, replace div with HTML response from Server
     * @param  {string} response html response for replacing postimagediv
     */
    function onImageAssigned ( response ) {
     
      if ( response ) {
        if (jQuery( '#postimagediv .hide-if-no-js' ).length > 1 ) {
          jQuery( '#postimagediv .hide-if-no-js' ).remove();
          jQuery( '#postimagediv .inside').append( response );
        } else {
          jQuery( '#postimagediv .hide-if-no-js' ).remove();
          jQuery( '#postimagediv .inside').append( response );
        }
      }
      // End Spinner
      tb_remove();
    }
    // Start Spinner
    tb_click();
    jQuery.post(ajaxurl, data, onImageAssigned );
  }
})( jQuery );
