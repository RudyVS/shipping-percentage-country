<?php
/**
 * Plugin Name: Shipping Method Based on Percentage
 * Description: Método de envío personalizado con reglas dinámicas visibles en el frontend en WooCommerce 4.9, incluyendo validación por tipo de producto.
 * Version: 3.7
 * Author: Inspire Creative Studio
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Crear tabla al activar el plugin
register_activation_hook( __FILE__, 'csm_create_table' );
function csm_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_shipping_rules';
    $charset_collate = $wpdb->get_charset_collate();

    // Verificar si la tabla ya existe
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        // Crear la tabla si no existe
        $sql = "CREATE TABLE $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            country VARCHAR(255) NOT NULL,
            categories VARCHAR(255) NOT NULL,
            product_types VARCHAR(255) NOT NULL,
            percentage FLOAT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Verificar errores después de crear la tabla
        if ( !empty( $wpdb->last_error ) ) {
            // Registro del error en el log de WordPress para evitar interrumpir la ejecución
            error_log( 'Database error: ' . $wpdb->last_error );
        }
    }
}

// Asegurarse de que WooCommerce esté activo antes de usar funciones específicas de WooCommerce
add_action( 'plugins_loaded', 'csm_check_woocommerce' );
function csm_check_woocommerce() {
    if ( class_exists( 'WooCommerce' ) ) {
        // Agregar pestaña en WooCommerce > Settings > Shipping
        add_filter( 'woocommerce_get_sections_shipping', 'csm_add_shipping_section' );
        add_action( 'woocommerce_cart_totals_after_shipping', 'csm_display_shipping_cost' );
        add_action( 'admin_menu', 'csm_add_shipping_page' );
    }
}

// Agregar pestaña en WooCommerce > Settings > Shipping
function csm_add_shipping_section( $sections ) {
    $sections['custom_shipping_method'] = __( 'Shipping Based on Percentage', 'custom-shipping-method' );
    return $sections;
}

// Mostrar el costo de envío en el carrito
function csm_display_shipping_cost() {
    $total_cart = WC()->cart->get_cart_contents_total();
    $rules = csm_get_applicable_shipping_rules();

    foreach ( $rules as $rule ) {
        $percentage_cost = $total_cart * ( $rule->percentage / 100 );
        echo '<tr class="shipping-cost"><th>' . esc_html( $rule->title ) . '</th><td>' . wc_price( $percentage_cost ) . '</td></tr>';
    }
}

// Obtener las reglas de envío aplicables
function csm_get_applicable_shipping_rules() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_shipping_rules';
    $rules = $wpdb->get_results( "SELECT * FROM $table_name WHERE active = 1" );
    $applicable_rules = [];

    // Obtener el país del cliente
    $customer_country = WC()->customer->get_billing_country();

    // Obtener las categorías y tipos de productos del carrito
    $cart_items = WC()->cart->get_cart();
    $cart_categories = [];
    $cart_product_types = [];

    foreach ( $cart_items as $cart_item ) {
        $product = $cart_item['data'];

        // Obtener las categorías del producto
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( $terms ) {
            foreach ( $terms as $term ) {
                $cart_categories[] = $term->slug;
            }
        }

        // Obtener los tipos de productos (si se tiene un campo personalizado para tipo de producto)
        $product_type = get_post_meta( $product->get_id(), '_product_type', true );
        if ( $product_type ) {
            $cart_product_types[] = $product_type;
        }
    }

    // Filtrar las reglas según el país, categorías y tipos de productos
    foreach ( $rules as $rule ) {
        // Validación de país
        if ( $rule->country && $rule->country !== $customer_country ) {
            continue;
        }

        // Validación de categorías
        $rule_categories = explode( ',', $rule->categories );
        if ( !empty( $rule_categories ) && !array_intersect( $cart_categories, $rule_categories ) ) {
            continue;
        }

        // Validación de tipos de productos
        $rule_product_types = explode( ',', $rule->product_types );
        if ( !empty( $rule_product_types ) && !array_intersect( $cart_product_types, $rule_product_types ) ) {
            continue;
        }

        // Si pasa todas las validaciones, agregar a la lista de reglas aplicables
        $applicable_rules[] = $rule;
    }

    return $applicable_rules;
}

// Agregar formulario de reglas en la página de administración
function csm_add_shipping_page() {
    add_submenu_page(
        'woocommerce',
        'Custom Shipping Rules',
        'Shipping Rules',
        'manage_options',
        'custom_shipping_rule_page',
        'csm_shipping_page'
    );
}

function csm_shipping_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_shipping_rules';

    // Mensajes de éxito o error
    if ( isset( $_GET['message'] ) ) {
        $message = '';
        switch ( $_GET['message'] ) {
            case 'saved':
                $message = '<div class="updated"><p>La regla de envío ha sido guardada correctamente.</p></div>';
                break;
            case 'deleted':
                $message = '<div class="updated"><p>La regla de envío ha sido eliminada correctamente.</p></div>';
                break;
            case 'error':
                $message = '<div class="error"><p>Hubo un error al guardar la regla de envío. Por favor, intenta nuevamente.</p></div>';
                break;
        }
        echo $message;
    }

    // Verificar si estamos editando una regla
    $rule = null;
    if ( isset( $_GET['edit_rule'] ) ) {
        $rule = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = " . intval( $_GET['edit_rule'] ) );
    }

    // Verificar si se ha pasado la solicitud para eliminar una regla
    if ( isset( $_GET['delete_rule'] ) ) {
        // Obtener el ID de la regla a eliminar y asegurarse de que sea un número entero
        $rule_id = intval( $_GET['delete_rule'] );

        // Eliminar la regla de la base de datos
        $deleted = $wpdb->delete( $table_name, [ 'id' => $rule_id ] );

        // Verificar si la eliminación fue exitosa
        if ( $deleted ) {
            // Redirigir al usuario a la página de configuración con un mensaje de éxito
            wp_redirect( add_query_arg( 'message', 'deleted', menu_page_url( 'custom_shipping_rule_page', false ) ) );
        } else {
            // En caso de error, redirigir con un mensaje de error
            wp_redirect( add_query_arg( 'message', 'error', menu_page_url( 'custom_shipping_rule_page', false ) ) );
        }
        exit; // Terminar la ejecución para evitar más procesamiento
    }
    }

    // Procesar formulario de guardado
    if ( isset( $_POST['csm_save_rule'] ) ) {
        csm_save_rule( isset( $_POST['rule_id'] ) ? intval( $_POST['rule_id'] ) : null );
    }

    // Asegúrate de que WooCommerce está cargado y activo
    if ( class_exists( 'WooCommerce' ) ) {
        // Usamos un gancho para asegurarnos de que WooCommerce esté completamente cargado
        add_action('init', function() {
            // Obtener países, categorías y tipos de productos
            $countries = wc_get_countries();
            $categories = get_terms( [
                'taxonomy' => 'product_cat',
                'orderby' => 'name',
                'hide_empty' => false,
            ] );
            $product_types = ['simple', 'variable', 'grouped', 'external'];

            // Mostrar resultados (opcional)
            echo '<pre>';
            print_r($countries);
            print_r($categories);
            print_r($product_types);
            echo '</pre>';
        });
    } else {
        // Si WooCommerce no está activo, mostramos un mensaje de error
        echo '<div style="color: red; font-weight: bold;">ERROR: WooCommerce no está activo. Asegúrate de que WooCommerce esté instalado y activado.</div>';
    }

    ?>
    <div class="wrap">
    <h1><?php echo isset( $rule ) ? 'Edit Shipping Rule' : 'Add New Shipping Rule'; ?></h1>
    <form id="shipping-rule-form" method="post">
        <?php wp_nonce_field( 'save_shipping_rule', 'csm_shipping_rule_nonce' ); ?>

    <?php if ( isset( $rule ) ) : ?>
        <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule->id ); ?>" />
    <?php endif; ?>
    
    <table class="form-table">
        <tr>
            <th><label for="title"><?php esc_html_e( 'Title', 'custom-shipping-method' ); ?></label></th>
            <td><input type="text" id="title" name="title" value="<?php echo esc_attr( $rule->title ?? '' ); ?>" class="regular-text" required /></td>
        </tr>

        <tr>
            <th scope="row"><?php _e('País', 'woocommerce'); ?></th>
            <td>
                <select name="country" id="country" required>
                    <option value=""><?php _e('Selecciona un país', 'woocommerce'); ?></option>
                    <?php 
                    // Obtener los países de WooCommerce
                    $countries = WC()->countries->get_countries();
                    foreach ($countries as $country_code => $country_name): ?>
                        <option value="<?php echo esc_attr($country_code); ?>">
                            <?php echo esc_html($country_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th><label for="categories"><?php esc_html_e( 'Categories', 'custom-shipping-method' ); ?></label></th>
            <td>
                <select id="categories" name="categories[]" class="postform" multiple="multiple" required style="width: 100%;">
                    <option value="all" <?php echo in_array( 'all', explode( ',', $rule->categories ?? '' ) ) ? 'selected' : ''; ?>
                        <?php esc_html_e( 'All', 'custom-shipping-method' ); ?>
                    </option>
                    <?php 
                    // Obtener todas las categorías de productos
                    $categories = get_terms( [
                        'taxonomy' => 'product_cat',
                        'orderby'  => 'name',
                        'hide_empty' => false,
                    ] );
                    
                    if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) :
                        foreach ( $categories as $category ) :
                    ?>
                        <option value="<?php echo esc_attr( $category->slug ); ?>" <?php echo in_array( $category->slug, explode( ',', $rule->categories ?? '' ) ) ? 'selected' : ''; ?>
                            <?php echo esc_html( $category->name ); ?>
                        </option>
                    <?php endforeach; endif; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="product_types"><?php esc_html_e( 'Product Types', 'custom-shipping-method' ); ?></label></th>
            <td>
                <input type="text" id="product_types" name="product_types" class="regular-text" value="<?php echo esc_attr( implode( ', ', explode( ',', $rule->product_types ?? '' ) ) ); ?>" disabled />
                <p class="description"><?php esc_html_e( 'These are predefined product types, and cannot be modified by the user.', 'custom-shipping-method' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="percentage"><?php esc_html_e( 'Percentage', 'custom-shipping-method' ); ?></label></th>
            <td><input type="number" id="percentage" name="percentage" value="<?php echo esc_attr( $rule->percentage ?? '' ); ?>" min="0" max="100" required /></td>
        </tr>

        <tr>
            <th><label for="active"><?php esc_html_e( 'Active', 'custom-shipping-method' ); ?></label></th>
            <td><input type="checkbox" id="active" name="active" value="1" <?php echo isset($rule) && $rule->active ? 'checked' : ''; ?> /></td>
        </tr>

    </table>

    <p class="submit">
        <input type="submit" name="csm_save_rule" id="submit" class="button button-primary" value="<?php echo isset( $rule ) ? esc_attr( 'Update Rule' ) : esc_attr( 'Save Rule' ); ?>" />
    </p>
</form>

</div>


    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Asegurarse de que jQuery esté cargado
        if (typeof jQuery === 'undefined') {
            console.error('jQuery no está cargado');
            return;
        }

        // Inicializar Select2 en el campo de categorías
        $('#categories').select2({
            placeholder: "<?php echo esc_js(__('Selecciona categorías', 'woocommerce')); ?>", // Usar esc_js para seguridad en cadenas
            allowClear: true
        });

        // Verificar si el formulario existe
        if ($('#shipping-rule-form').length === 0) {
            console.error('El formulario de reglas de envío no se encuentra en la página.');
            return;
        }

        // Generar nonce de seguridad desde PHP para evitar posibles ataques
        var nonce = '<?php echo wp_create_nonce('custom_shipping_nonce'); ?>'; // Generar nonce

        // Verificar que ajaxurl esté definido y usar la URL correcta para AJAX
        if (typeof ajaxurl === 'undefined') {
            console.error('ajaxurl no está definido.');
            return;
        }

        // Manejar el submit del formulario con AJAX
        $('#shipping-rule-form').on('submit', function(e) {
            e.preventDefault(); // Prevenir el comportamiento normal de submit

            const formData = $(this).serialize(); // Serializar los datos del formulario

            // Enviar la solicitud AJAX
            $.post(ajaxurl, {
                action: 'save_shipping_rule',
                nonce: nonce,
                rule: formData
            }, function(response) {
                if (response.success) {
                    alert('<?php echo esc_js(__('Regla guardada exitosamente.', 'woocommerce')); ?>');
                    // Redirigir a la página de configuración
                    window.location.href = "<?php echo esc_url(admin_url('admin.php?page=custom-shipping-settings')); ?>"; // Usar esc_url para seguridad
                } else {
                    alert('<?php echo esc_js(__('Error guardando la regla: ', 'woocommerce')); ?>' + response.data);
                }
            }).fail(function(xhr, status, error) {
                console.error('Error AJAX: ' + error);
                alert('<?php echo esc_js(__('Ocurrió un error en la solicitud. Intente nuevamente.', 'woocommerce')); ?>');
            });
        });
    });
    </script>


    <h2>Shipping Rules</h2>

    <table class="wp-list-table widefat fixed striped posts">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Title', 'custom-shipping-method' ); ?></th>
            <th><?php esc_html_e( 'Country', 'custom-shipping-method' ); ?></th>
            <th><?php esc_html_e( 'Categories', 'custom-shipping-method' ); ?></th>
            <th><?php esc_html_e( 'Percentage', 'custom-shipping-method' ); ?></th>
            <th><?php esc_html_e( 'Active', 'custom-shipping-method' ); ?></th>
            <th><?php esc_html_e( 'Actions', 'custom-shipping-method' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Recupera las reglas de envío desde la base de datos
        $rules = $wpdb->get_results( "SELECT * FROM $table_name" );

        if ( empty( $rules ) ) :
            // Si no hay reglas creadas, mostrar un mensaje
        ?>
            <tr>
                <td colspan="6"><?php esc_html_e( 'No shipping rules have been created yet.', 'custom-shipping-method' ); ?></td>
            </tr>
        <?php else : ?>
            <?php foreach ( $rules as $rule ) : ?>
                <tr>
                    <td><?php echo esc_html( $rule->title ); ?></td>
                    <td><?php echo esc_html( $rule->country ); ?></td>
                    <td><?php echo esc_html( implode( ', ', explode( ',', $rule->categories ) ) ); ?></td>
                    <td><?php echo esc_html( $rule->percentage ); ?>%</td>
                    <td><?php echo $rule->active ? 'Yes' : 'No'; ?></td>
                    <td>
                        <a href="?page=custom_shipping_rule_page&edit_rule=<?php echo esc_attr( $rule->id ); ?>"><?php esc_html_e( 'Edit', 'custom-shipping-method' ); ?></a> |
                        <a href="?page=custom_shipping_rule_page&delete_rule=<?php echo esc_attr( $rule->id ); ?>" onclick="return confirm('<?php esc_html_e( 'Are you sure?', 'custom-shipping-method' ); ?>')"><?php esc_html_e( 'Delete', 'custom-shipping-method' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    </table>
    </div>
    <?php

// Función que maneja la solicitud AJAX y guarda la regla de envío
function csm_save_rule( $rule_id = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_shipping_rules';

    // Sanitizar los valores recibidos del formulario
    $title = sanitize_text_field( $_POST['title'] );
    $country = sanitize_text_field( $_POST['country'] );
    $categories = isset( $_POST['categories'] ) ? implode( ',', $_POST['categories'] ) : '';
    $product_types = sanitize_text_field( $_POST['product_types'] );
    $percentage = floatval( $_POST['percentage'] );
    $active = isset( $_POST['active'] ) ? 1 : 0;

    // Verificar si estamos editando o agregando una nueva regla
    if ( $rule_id ) {
        // Actualizar una regla existente
        $wpdb->update(
            $table_name,
            [
                'title' => $title,
                'country' => $country,
                'categories' => $categories,
                'product_types' => $product_types,
                'percentage' => $percentage,
                'active' => $active
            ],
            [ 'id' => $rule_id ]
        );
        $message = 'saved';
    } else {
        // Insertar una nueva regla
        $wpdb->insert(
            $table_name,
            [
                'title' => $title,
                'country' => $country,
                'categories' => $categories,
                'product_types' => $product_types,
                'percentage' => $percentage,
                'active' => $active
            ]
        );
        $message = 'saved';
    }

    // Redirigir con el mensaje de éxito
    wp_redirect( add_query_arg( 'message', $message, menu_page_url( 'custom_shipping_rule_page', false ) ) );
    exit;
}