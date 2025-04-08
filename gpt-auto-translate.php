<?php
/**
 * Plugin Name:       GPT Auto Translate
 * Plugin URI:        https://github.com/WakamTech/GPT-Auto-Translate
 * Description:       Traduit automatiquement le contenu de WordPress en utilisant l'API GPT et gère les versions linguistiques.
 * Version:           0.2.0-acf
 * Author:            BO-VO Digital
 * Author URI:        https://bovo-digital.tech/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gpt-auto-translate
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Définition des constantes principales du plugin.
 */
define( 'GPT_AUTO_TRANSLATE_VERSION', '0.2.0-acf' );
define( 'GPT_AUTO_TRANSLATE_PATH', plugin_dir_path( __FILE__ ) ); // Chemin absolu vers le dossier du plugin, ex: /var/www/html/wp-content/plugins/gpt-auto-translate/
define( 'GPT_AUTO_TRANSLATE_URL', plugin_dir_url( __FILE__ ) );   // URL vers le dossier du plugin, ex: http://yoursite.local/wp-content/plugins/gpt-auto-translate/
define( 'GPT_AUTO_TRANSLATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) ); // Nom de base du plugin, ex: gpt-auto-translate/gpt-auto-translate.php


/**
 * Classe principale du plugin.
 * Utilise le pattern Singleton pour s'assurer qu'une seule instance est chargée.
 */
final class Gpt_Auto_Translate {

    /**
     * Version du plugin.
     * @var string
     */
    private $version;

    /**
     * Instance unique de la classe.
     * @var Gpt_Auto_Translate|null
     */
    private static $instance = null;

    /**
     * Constructeur privé pour Singleton.
     */
    private function __construct() {
        $this->version = GPT_AUTO_TRANSLATE_VERSION;
        $this->setup_hooks();
    }

    /**
     * Méthode pour obtenir l'instance unique de la classe.
     * @return Gpt_Auto_Translate
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Empêche le clonage de l'instance (Singleton).
     */
    private function __clone() {}

    /**
     * Empêche la désérialisation de l'instance (Singleton).
     */
    public function __wakeup() {}

    /**
     * Met en place les hooks WordPress nécessaires.
     */
    private function setup_hooks() {
        // Ajout de la page de réglages dans le menu Administration > Réglages
        add_action( 'admin_menu', [ $this, 'add_admin_menu_page' ] );

        // Enregistrement des paramètres du plugin
        add_action( 'admin_init', [ $this, 'register_plugin_settings' ] );

        // Ajout de la Meta Box sur les écrans d'édition
        add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ], 10, 2 );

        // Charger les scripts admin UNIQUEMENT sur les pages concernées
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // Enregistrer le gestionnaire AJAX
        add_action( 'wp_ajax_gpt_auto_translate_request', [ $this, 'handle_ajax_translation_request' ] );
        // Note: pas de wp_ajax_nopriv_ car seuls les utilisateurs connectés peuvent traduire

        // Ajouter les balises hreflang dans le <head>
        // add_action( 'wp_head', [ $this, 'add_hreflang_tags' ] );

        // Enregistrer le shortcode pour le sélecteur de langue
        // add_shortcode( 'gpt_language_switcher', [ $this, 'render_language_switcher_shortcode' ] );

        // Register the fields for the REST API
        add_action( 'rest_api_init', [ $this, 'register_custom_acf_rest_fields' ] ); // Renamed for clarity

        // Charger le text domain pour la traduction
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

        // --- Autres hooks viendront ici ---
    }

    /**
     * Charge le fichier de traduction (.mo) du plugin.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'gpt-auto-translate',                // Le text domain unique
            false,                               // Déprécié, toujours false
            dirname( GPT_AUTO_TRANSLATE_PLUGIN_BASENAME ) . '/languages/' // Chemin relatif vers le dossier contenant les fichiers .mo
        );
    }

    /**
     * Fonction exécutée à l'activation du plugin.
     * Méthode statique car appelée avant l'instanciation potentielle.
     */
    public static function activate() {
        // Actions à réaliser une seule fois à l'activation
        // Ex: Créer des options par défaut, vérifier des dépendances, etc.
        // flush_rewrite_rules(); // Important si on ajoute des Custom Post Types ou des règles de réécriture
        
        // Pour l'instant, on peut juste initialiser une option pour savoir que l'activation a eu lieu
        if ( get_option( 'gpt_auto_translate_activated', false ) === false ) {
             add_option( 'gpt_auto_translate_activated', time() );
        }
    }

    /**
     * Fonction exécutée à la désactivation du plugin.
     * Méthode statique.
     */
    public static function deactivate() {
        // Actions à réaliser à la désactivation
        // Ex: Nettoyer des tâches cron, etc.
        // Ne PAS supprimer les données utilisateur (options, traductions) ici généralement.
        // flush_rewrite_rules();

        // On peut supprimer l'option d'activation si on le souhaite
        // delete_option( 'gpt_auto_translate_activated' );
    }

    // --- Les autres méthodes du plugin viendront ici ---
    // Par exemple : add_admin_menu(), enqueue_admin_scripts(), handle_ajax_request(), translate_content() etc.

    /**
     * Ajoute la page de réglages au menu d'administration sous "Réglages".
     */
    public function add_admin_menu_page() {
        add_options_page(
            __( 'GPT Auto Translate Settings', 'gpt-auto-translate' ), // Titre de la page (dans <title>)
            __( 'GPT Auto Translate', 'gpt-auto-translate' ),          // Titre du menu
            'manage_options',                                         // Capacité requise pour voir le menu
            'gpt-auto-translate-settings',                            // Slug unique de la page de menu
            [ $this, 'render_settings_page_html' ]                    // Fonction pour afficher le contenu de la page
        );
    }
    
    /**
     * Affiche le HTML de la page de réglages.
     */
    public function render_settings_page_html() {
        // Vérifier si l'utilisateur actuel a la permission de voir cette page
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                // Output les champs de sécurité, clés cachées, etc. pour notre groupe de réglages
                settings_fields( 'gpt_auto_translate_settings_group' );

                // Output les sections et champs enregistrés pour cette page
                do_settings_sections( 'gpt-auto-translate-settings' );

                // Affiche le bouton de sauvegarde
                submit_button( __( 'Save Settings', 'gpt-auto-translate' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enregistre les paramètres, sections et champs via l'API Settings.
     */
    public function register_plugin_settings() {
        // Enregistre un groupe de paramètres. Toutes nos options seront stockées
        // dans une seule entrée de la table wp_options, sous forme de tableau.
        register_setting(
            'gpt_auto_translate_settings_group',      // Nom du groupe (doit correspondre à settings_fields())
            'gpt_auto_translate_options',             // Nom de l'option dans la base de données
            [ $this, 'sanitize_options' ]             // Fonction de rappel pour nettoyer les données avant sauvegarde
        );

        // SECTION 1 : Paramètres API
        add_settings_section(
            'gpt_auto_translate_api_settings_section', // ID unique de la section
            __( 'API Settings', 'gpt-auto-translate' ), // Titre de la section
            [ $this, 'render_api_settings_section_text' ], // Fonction pour afficher un texte descriptif (optionnel)
            'gpt-auto-translate-settings'              // Slug de la page où afficher cette section
        );

        // Champ : Clé API GPT
        add_settings_field(
            'gpt_api_key',                             // ID unique du champ
            __( 'GPT API Key', 'gpt-auto-translate' ),   // Label du champ
            [ $this, 'render_api_key_field' ],         // Fonction pour afficher le champ HTML
            'gpt-auto-translate-settings',             // Slug de la page
            'gpt_auto_translate_api_settings_section'  // ID de la section parente
        );

         // ***NOUVEAU CHAMP*** : Modèle GPT
        add_settings_field(
            'gpt_model',                               // ID du champ
            __( 'GPT Model', 'gpt-auto-translate' ),     // Label
            [ $this, 'render_gpt_model_field' ],       // Fonction de rendu
            'gpt-auto-translate-settings',
            'gpt_auto_translate_api_settings_section' // Dans la même section API
        );

        // SECTION 2 : Paramètres de Langue
        add_settings_section(
            'gpt_auto_translate_language_settings_section',
            __( 'Language Settings', 'gpt-auto-translate' ),
            [ $this, 'render_language_settings_section_text' ],
            'gpt-auto-translate-settings'
        );

        // Champ : Langues Cibles
        add_settings_field(
            'target_languages_str', // Nouvel ID pour le champ texte
            __( 'Target Languages', 'gpt-auto-translate' ),
            [ $this, 'render_target_languages_field' ], // La fonction de rendu va changer
            'gpt-auto-translate-settings',
            'gpt_auto_translate_language_settings_section'
        );

        // Champ : Types de contenu (on l'ajoute ici, même si la logique viendra plus tard)
        add_settings_field(
            'target_post_types',
            __( 'Content Types to Translate', 'gpt-auto-translate' ),
            [ $this, 'render_target_post_types_field' ],
            'gpt-auto-translate-settings',
            'gpt_auto_translate_language_settings_section' // Ou une nouvelle section "Content Settings"
        );
    }

    /**
     * Affiche le texte descriptif pour la section API.
     */
    public function render_api_settings_section_text() {
        echo '<p>' . __( 'Enter your API key for the GPT service (e.g., OpenAI).', 'gpt-auto-translate' ) . '</p>';
    }

    /**
     * Affiche le champ HTML pour la clé API.
     */
    public function render_api_key_field() {
        $options = get_option( 'gpt_auto_translate_options' );
        $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
        ?>
        <input type='password' name='gpt_auto_translate_options[api_key]' value='<?php echo esc_attr( $api_key ); ?>' class='regular-text'>
        <?php
    }

    /**
     * Affiche le texte descriptif pour la section Langues.
     */
    public function render_language_settings_section_text() {
        echo '<p>' . __( 'Select the languages you want to translate content into. The source language is assumed to be English.', 'gpt-auto-translate' ) . '</p>';
    }



    /**
     * Affiche les cases à cocher pour les types de contenu cibles.
     */
    public function render_target_post_types_field() {
        $options = get_option( 'gpt_auto_translate_options' );
        $selected_post_types = isset( $options['target_post_types'] ) && is_array($options['target_post_types']) ? $options['target_post_types'] : [];

        // Récupère les types de contenu publics (exclut les révisions, menus, etc.)
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        foreach ( $post_types as $post_type ) {
            // Exclure les "attachments" si non désiré
            if ($post_type->name === 'attachment') {
                continue;
            }
            ?>
            <label style="margin-right: 15px;">
                <input type='checkbox' name='gpt_auto_translate_options[target_post_types][]' value='<?php echo esc_attr( $post_type->name ); ?>' <?php checked( in_array( $post_type->name, $selected_post_types ), true ); ?>>
                <?php echo esc_html( $post_type->label ); ?> (<?php echo esc_html($post_type->name); ?>)
            </label><br>
            <?php
        }
        echo '<p class="description">' . __('Select the content types where the translation metabox should appear.', 'gpt-auto-translate') . '</p>';
    }

    /**
     * Ajoute la Meta Box de traduction sur les écrans d'édition des types
     * de contenu sélectionnés dans les réglages.
     *
     * @param string $post_type Le type de contenu de l'écran d'édition actuel.
     * @param WP_Post $post L'objet post en cours d'édition.
     */
    public function add_translation_meta_box( $post_type, $post ) {
        // Récupérer les options sauvegardées
        $options = get_option( 'gpt_auto_translate_options' );
        $target_post_types = isset( $options['target_post_types'] ) ? $options['target_post_types'] : [];

        // Vérifier si le type de contenu actuel est dans la liste des types ciblés
        if ( ! empty( $target_post_types ) && in_array( $post_type, $target_post_types ) ) {

            // Vérifier si le post actuel est une traduction (pour adapter l'affichage)
            $is_translation = get_post_meta($post->ID, '_gpt_original_post_id', true);
            $box_title = $is_translation ?
                            __( 'Translation Status (Source)', 'gpt-auto-translate' ) :
                            __( 'GPT Auto Translate Status', 'gpt-auto-translate' );


            add_meta_box(
                'gpt_auto_translate_meta_box',          // ID unique de la meta box
                $box_title,                             // Titre de la meta box
                [ $this, 'render_meta_box_content' ],   // Fonction de rappel pour afficher le contenu
                $post_type,                             // Écran (type de contenu) où afficher la box
                'side',                                 // Contexte (side, normal, advanced)
                'high'                                  // Priorité (high, core, default, low)
                // Le dernier argument $callback_args n'est pas utilisé ici mais pourrait l'être
            );
        }
    }

    /**
     * Affiche le contenu HTML de la Meta Box de traduction.
     *
     * @param WP_Post $post L'objet post en cours d'édition.
     */
    public function render_meta_box_content( $post ) {
        // Récupérer les options (surtout les langues cibles)
        $options = get_option( 'gpt_auto_translate_options' );
        $target_languages = isset( $options['target_languages'] ) && is_array($options['target_languages']) ? $options['target_languages'] : [];

        // Sécurité : Ajout d'un nonce pour vérifier l'intention lors du futur traitement AJAX/POST
        // L'action inclut l'ID du post pour une meilleure spécificité
        wp_nonce_field( 'gpt_translate_action_' . $post->ID, 'gpt_translate_nonce' );

        // Récupérer les informations de traduction existantes pour ce post
        $translation_ids = get_post_meta( $post->ID, '_gpt_translation_ids', true );
        $translation_ids = is_array( $translation_ids ) ? $translation_ids : []; // S'assurer que c'est un tableau

        // Récupérer l'ID de l'original si ce post EST une traduction
        $original_post_id = get_post_meta( $post->ID, '_gpt_original_post_id', true );

        echo '<div>'; // Conteneur pour la mise en page

        if ($original_post_id) {
            // === CAS 1: Le post actuel EST une traduction ===
            $original_post = get_post($original_post_id);
            $language_code = get_post_meta( $post->ID, '_gpt_language', true );
            $language_name = $this->get_language_name($language_code); // Helper function à créer

            echo '<p>';
            printf(
                /* translators: 1: Language name, 2: Link to edit original post */
                esc_html__( 'This is the %1$s translation of: %2$s.', 'gpt-auto-translate' ),
                '<strong>' . esc_html( $language_name ) . '</strong>',
                $original_post ? '<a href="' . esc_url( get_edit_post_link( $original_post_id ) ) . '">' . esc_html( $original_post->post_title ) . '</a>' : '#' . $original_post_id
            );
            echo '</p>';
            echo '<p>' . esc_html__( 'To update all translations, please edit the original English post and use the translate button there.', 'gpt-auto-translate' ) . '</p>';

        } else {
            // === CAS 2: Le post actuel est l'original (ou n'a pas de lien défini) ===
            if ( empty( $target_languages ) ) {
                echo '<p>' . esc_html__( 'Please select target languages in the plugin settings.', 'gpt-auto-translate' ) . '</p>';
            } else {
                // MODIFIÉ : Changement du titre pour refléter la nouvelle logique
                echo '<strong>' . esc_html__( 'Translation Status (V2 - Meta Check):', 'gpt-auto-translate' ) . '</strong>';
                echo '<ul style="margin-top: 5px;">';
    
                foreach ( $target_languages as $lang_code ) {
                    $language_name = $this->get_language_name( $lang_code );
                    // La clé de métadonnée où nous stockons les traductions V2
                    $lang_meta_key = $lang_code . '_messages_001';
                    $status_text = '<li>' . esc_html( $language_name ) . ' (' . esc_html($lang_code) . '): ';
    
                    // NOUVELLE VÉRIFICATION : Recherche de la métadonnée V2 sur le post actuel
                    $lang_meta_data = get_post_meta($post->ID, $lang_meta_key, true);
    
                    // Vérification plus robuste : la méta existe, est un tableau, a une clé 'data' qui est un tableau non vide ?
                    if (is_array($lang_meta_data) && isset($lang_meta_data['data']) && is_array($lang_meta_data['data']) && !empty($lang_meta_data['data'])) {
                         // Condition V2 remplie : On considère que la traduction existe.
                         $status_text .= '<strong>' . __( 'Translated (Meta Found)', 'gpt-auto-translate' ) . '</strong>';
                         // Pas de lien "Edit" ici, la modification se fait sur le post original.
                    } else {
                         // Condition V2 non remplie : Pas de méta ou méta vide/invalide.
                         $status_text .= __( 'Not Translated', 'gpt-auto-translate' );
                    }
    
                    $status_text .= '</li>';
                    echo $status_text; // Échappement déjà fait dans les composants
                }
                echo '</ul>';
    
                // AJOUT : Petite note pour expliquer la signification du statut V2
                echo '<p style="font-size:small; font-style:italic; margin-top: 5px;">' . esc_html__('Status based on presence of translation metadata within this post.', 'gpt-auto-translate') . '</p>';
    
    
                // Ajouter un conteneur pour les messages AJAX futurs (inchangé)
                echo '<div id="gpt-translate-message-area" style="margin-top: 10px;"></div>';
    
                // Le bouton qui déclenchera la traduction (inchangé)
                $button_text = __( 'Translate / Update All', 'gpt-auto-translate' );
                $disabled_attr = (get_post_status($post->ID) === 'auto-draft') ? ' disabled="disabled"' : '';
                $disabled_title = (get_post_status($post->ID) === 'auto-draft') ? ' title="' . esc_attr__('Save draft first to enable translation', 'gpt-auto-translate') . '"' : '';
    
                echo '<button type="button" id="gpt-translate-button" class="button button-primary" data-postid="' . esc_attr( $post->ID ) . '"' . $disabled_attr . $disabled_title . '>' . esc_html( $button_text ) . '</button>';
            }
        }
    
        echo '</div>'; // Fin du conteneur
    }

    /**
     * Helper function to get the display name of a language from its code.
     * (Could be expanded later)
     * @param string $code Language code (e.g., 'es')
     * @return string Language name (e.g., 'Spanish')
     */
    private function get_language_name($code) {
        // Liste basique (peut être étendue)
        $common_names = [
                'ab' => __( 'Abkhazian', 'gpt-auto-translate' ),
                'aa' => __( 'Afar', 'gpt-auto-translate' ),
                'af' => __( 'Afrikaans', 'gpt-auto-translate' ),
                'ak' => __( 'Akan', 'gpt-auto-translate' ),
                'sq' => __( 'Albanian', 'gpt-auto-translate' ),
                'am' => __( 'Amharic', 'gpt-auto-translate' ),
                'ar' => __( 'Arabic', 'gpt-auto-translate' ),
                'an' => __( 'Aragonese', 'gpt-auto-translate' ),
                'hy' => __( 'Armenian', 'gpt-auto-translate' ),
                'as' => __( 'Assamese', 'gpt-auto-translate' ),
                'av' => __( 'Avaric', 'gpt-auto-translate' ),
                'ae' => __( 'Avestan', 'gpt-auto-translate' ),
                'ay' => __( 'Aymara', 'gpt-auto-translate' ),
                'az' => __( 'Azerbaijani', 'gpt-auto-translate' ),
                'bm' => __( 'Bambara', 'gpt-auto-translate' ),
                'ba' => __( 'Bashkir', 'gpt-auto-translate' ),
                'eu' => __( 'Basque', 'gpt-auto-translate' ),
                'be' => __( 'Belarusian', 'gpt-auto-translate' ),
                'bn' => __( 'Bengali', 'gpt-auto-translate' ),
                'bi' => __( 'Bislama', 'gpt-auto-translate' ),
                'bs' => __( 'Bosnian', 'gpt-auto-translate' ),
                'br' => __( 'Breton', 'gpt-auto-translate' ),
                'bg' => __( 'Bulgarian', 'gpt-auto-translate' ),
                'my' => __( 'Burmese', 'gpt-auto-translate' ),
                'ca' => __( 'Catalan', 'gpt-auto-translate' ), // Note: Table lists "Catalan, Valencian"
                'ch' => __( 'Chamorro', 'gpt-auto-translate' ),
                'ce' => __( 'Chechen', 'gpt-auto-translate' ),
                'ny' => __( 'Chichewa', 'gpt-auto-translate' ), // Note: Table lists "Chichewa, Chewa, Nyanja"
                'zh' => __( 'Chinese', 'gpt-auto-translate' ),
                'cu' => __( 'Church Slavonic', 'gpt-auto-translate' ), // Note: Table lists "Church Slavonic, Old Slavonic, Old Church Slavonic"
                'cv' => __( 'Chuvash', 'gpt-auto-translate' ),
                'kw' => __( 'Cornish', 'gpt-auto-translate' ),
                'co' => __( 'Corsican', 'gpt-auto-translate' ),
                'cr' => __( 'Cree', 'gpt-auto-translate' ),
                'hr' => __( 'Croatian', 'gpt-auto-translate' ),
                'cs' => __( 'Czech', 'gpt-auto-translate' ),
                'da' => __( 'Danish', 'gpt-auto-translate' ),
                'dv' => __( 'Divehi', 'gpt-auto-translate' ), // Note: Table lists "Divehi, Dhivehi, Maldivian"
                'nl' => __( 'Dutch', 'gpt-auto-translate' ), // Note: Table lists "Dutch, Flemish"
                'dz' => __( 'Dzongkha', 'gpt-auto-translate' ),
                'en' => __( 'English', 'gpt-auto-translate' ),
                'eo' => __( 'Esperanto', 'gpt-auto-translate' ),
                'et' => __( 'Estonian', 'gpt-auto-translate' ),
                'ee' => __( 'Ewe', 'gpt-auto-translate' ),
                'fo' => __( 'Faroese', 'gpt-auto-translate' ),
                'fj' => __( 'Fijian', 'gpt-auto-translate' ),
                'fi' => __( 'Finnish', 'gpt-auto-translate' ),
                'fr' => __( 'French', 'gpt-auto-translate' ),
                'fy' => __( 'Western Frisian', 'gpt-auto-translate' ),
                'ff' => __( 'Fulah', 'gpt-auto-translate' ),
                'gd' => __( 'Scottish Gaelic', 'gpt-auto-translate' ), // Note: Table lists "Gaelic, Scottish Gaelic"
                'gl' => __( 'Galician', 'gpt-auto-translate' ),
                'lg' => __( 'Ganda', 'gpt-auto-translate' ),
                'ka' => __( 'Georgian', 'gpt-auto-translate' ),
                'de' => __( 'German', 'gpt-auto-translate' ),
                'el' => __( 'Greek', 'gpt-auto-translate' ), // Note: Table lists "Greek, Modern (1453–)"
                'kl' => __( 'Kalaallisut', 'gpt-auto-translate' ), // Note: Table lists "Kalaallisut, Greenlandic"
                'gn' => __( 'Guarani', 'gpt-auto-translate' ),
                'gu' => __( 'Gujarati', 'gpt-auto-translate' ),
                'ht' => __( 'Haitian', 'gpt-auto-translate' ), // Note: Table lists "Haitian, Haitian Creole"
                'ha' => __( 'Hausa', 'gpt-auto-translate' ),
                'he' => __( 'Hebrew', 'gpt-auto-translate' ),
                'hz' => __( 'Herero', 'gpt-auto-translate' ),
                'hi' => __( 'Hindi', 'gpt-auto-translate' ),
                'ho' => __( 'Hiri Motu', 'gpt-auto-translate' ),
                'hu' => __( 'Hungarian', 'gpt-auto-translate' ),
                'is' => __( 'Icelandic', 'gpt-auto-translate' ),
                'io' => __( 'Ido', 'gpt-auto-translate' ),
                'ig' => __( 'Igbo', 'gpt-auto-translate' ),
                'id' => __( 'Indonesian', 'gpt-auto-translate' ),
                'ia' => __( 'Interlingua', 'gpt-auto-translate' ), // Note: Table lists "Interlingua (International Auxiliary Language Association)"
                'ie' => __( 'Interlingue', 'gpt-auto-translate' ), // Note: Table lists "Interlingue, Occidental"
                'iu' => __( 'Inuktitut', 'gpt-auto-translate' ),
                'ik' => __( 'Inupiaq', 'gpt-auto-translate' ),
                'ga' => __( 'Irish', 'gpt-auto-translate' ),
                'it' => __( 'Italian', 'gpt-auto-translate' ),
                'ja' => __( 'Japanese', 'gpt-auto-translate' ),
                'jv' => __( 'Javanese', 'gpt-auto-translate' ),
                'kn' => __( 'Kannada', 'gpt-auto-translate' ),
                'kr' => __( 'Kanuri', 'gpt-auto-translate' ),
                'ks' => __( 'Kashmiri', 'gpt-auto-translate' ),
                'kk' => __( 'Kazakh', 'gpt-auto-translate' ),
                'km' => __( 'Khmer', 'gpt-auto-translate' ), // Note: Table lists "Central Khmer"
                'ki' => __( 'Kikuyu', 'gpt-auto-translate' ), // Note: Table lists "Kikuyu, Gikuyu"
                'rw' => __( 'Kinyarwanda', 'gpt-auto-translate' ),
                'ky' => __( 'Kyrgyz', 'gpt-auto-translate' ), // Note: Table lists "Kyrgyz, Kirghiz"
                'kv' => __( 'Komi', 'gpt-auto-translate' ),
                'kg' => __( 'Kongo', 'gpt-auto-translate' ),
                'ko' => __( 'Korean', 'gpt-auto-translate' ),
                'kj' => __( 'Kuanyama', 'gpt-auto-translate' ), // Note: Table lists "Kuanyama, Kwanyama"
                'ku' => __( 'Kurdish', 'gpt-auto-translate' ),
                'lo' => __( 'Lao', 'gpt-auto-translate' ),
                'la' => __( 'Latin', 'gpt-auto-translate' ),
                'lv' => __( 'Latvian', 'gpt-auto-translate' ),
                'li' => __( 'Limburgish', 'gpt-auto-translate' ), // Note: Table lists "Limburgan, Limburger, Limburgish"
                'ln' => __( 'Lingala', 'gpt-auto-translate' ),
                'lt' => __( 'Lithuanian', 'gpt-auto-translate' ),
                'lu' => __( 'Luba-Katanga', 'gpt-auto-translate' ),
                'lb' => __( 'Luxembourgish', 'gpt-auto-translate' ), // Note: Table lists "Luxembourgish, Letzeburgesch"
                'mk' => __( 'Macedonian', 'gpt-auto-translate' ),
                'mg' => __( 'Malagasy', 'gpt-auto-translate' ),
                'ms' => __( 'Malay', 'gpt-auto-translate' ),
                'ml' => __( 'Malayalam', 'gpt-auto-translate' ),
                'mt' => __( 'Maltese', 'gpt-auto-translate' ),
                'gv' => __( 'Manx', 'gpt-auto-translate' ),
                'mi' => __( 'Maori', 'gpt-auto-translate' ),
                'mr' => __( 'Marathi', 'gpt-auto-translate' ),
                'mh' => __( 'Marshallese', 'gpt-auto-translate' ),
                'mn' => __( 'Mongolian', 'gpt-auto-translate' ),
                'na' => __( 'Nauru', 'gpt-auto-translate' ),
                'nv' => __( 'Navajo', 'gpt-auto-translate' ), // Note: Table lists "Navajo, Navaho"
                'nd' => __( 'North Ndebele', 'gpt-auto-translate' ),
                'nr' => __( 'South Ndebele', 'gpt-auto-translate' ),
                'ng' => __( 'Ndonga', 'gpt-auto-translate' ),
                'ne' => __( 'Nepali', 'gpt-auto-translate' ),
                'no' => __( 'Norwegian', 'gpt-auto-translate' ),
                'nb' => __( 'Norwegian Bokmål', 'gpt-auto-translate' ),
                'nn' => __( 'Norwegian Nynorsk', 'gpt-auto-translate' ),
                'oc' => __( 'Occitan', 'gpt-auto-translate' ),
                'oj' => __( 'Ojibwa', 'gpt-auto-translate' ),
                'or' => __( 'Oriya', 'gpt-auto-translate' ),
                'om' => __( 'Oromo', 'gpt-auto-translate' ),
                'os' => __( 'Ossetian', 'gpt-auto-translate' ), // Note: Table lists "Ossetian, Ossetic"
                'pi' => __( 'Pali', 'gpt-auto-translate' ),
                'ps' => __( 'Pashto', 'gpt-auto-translate' ), // Note: Table lists "Pashto, Pushto"
                'fa' => __( 'Persian', 'gpt-auto-translate' ),
                'pl' => __( 'Polish', 'gpt-auto-translate' ),
                'pt' => __( 'Portuguese', 'gpt-auto-translate' ),
                'pa' => __( 'Punjabi', 'gpt-auto-translate' ), // Note: Table lists "Punjabi, Panjabi"
                'qu' => __( 'Quechua', 'gpt-auto-translate' ),
                'ro' => __( 'Romanian', 'gpt-auto-translate' ), // Note: Table lists "Romanian, Moldavian, Moldovan"
                'rm' => __( 'Romansh', 'gpt-auto-translate' ),
                'rn' => __( 'Rundi', 'gpt-auto-translate' ),
                'ru' => __( 'Russian', 'gpt-auto-translate' ),
                'se' => __( 'Northern Sami', 'gpt-auto-translate' ),
                'sm' => __( 'Samoan', 'gpt-auto-translate' ),
                'sg' => __( 'Sango', 'gpt-auto-translate' ),
                'sa' => __( 'Sanskrit', 'gpt-auto-translate' ),
                'sc' => __( 'Sardinian', 'gpt-auto-translate' ),
                'sr' => __( 'Serbian', 'gpt-auto-translate' ),
                'sn' => __( 'Shona', 'gpt-auto-translate' ),
                'sd' => __( 'Sindhi', 'gpt-auto-translate' ),
                'si' => __( 'Sinhala', 'gpt-auto-translate' ), // Note: Table lists "Sinhala, Sinhalese"
                'sk' => __( 'Slovak', 'gpt-auto-translate' ),
                'sl' => __( 'Slovenian', 'gpt-auto-translate' ),
                'so' => __( 'Somali', 'gpt-auto-translate' ),
                'st' => __( 'Southern Sotho', 'gpt-auto-translate' ),
                'es' => __( 'Spanish', 'gpt-auto-translate' ), // Note: Table lists "Spanish, Castilian"
                'su' => __( 'Sundanese', 'gpt-auto-translate' ),
                'sw' => __( 'Swahili', 'gpt-auto-translate' ),
                'ss' => __( 'Swati', 'gpt-auto-translate' ),
                'sv' => __( 'Swedish', 'gpt-auto-translate' ),
                'tl' => __( 'Tagalog', 'gpt-auto-translate' ),
                'ty' => __( 'Tahitian', 'gpt-auto-translate' ),
                'tg' => __( 'Tajik', 'gpt-auto-translate' ),
                'ta' => __( 'Tamil', 'gpt-auto-translate' ),
                'tt' => __( 'Tatar', 'gpt-auto-translate' ),
                'te' => __( 'Telugu', 'gpt-auto-translate' ),
                'th' => __( 'Thai', 'gpt-auto-translate' ),
                'bo' => __( 'Tibetan', 'gpt-auto-translate' ),
                'ti' => __( 'Tigrinya', 'gpt-auto-translate' ),
                'to' => __( 'Tonga', 'gpt-auto-translate' ), // Note: Table lists "Tonga (Tonga Islands)"
                'ts' => __( 'Tsonga', 'gpt-auto-translate' ),
                'tn' => __( 'Tswana', 'gpt-auto-translate' ),
                'tr' => __( 'Turkish', 'gpt-auto-translate' ),
                'tk' => __( 'Turkmen', 'gpt-auto-translate' ),
                'tw' => __( 'Twi', 'gpt-auto-translate' ),
                'ug' => __( 'Uighur', 'gpt-auto-translate' ), // Note: Table lists "Uighur, Uyghur"
                'uk' => __( 'Ukrainian', 'gpt-auto-translate' ),
                'ur' => __( 'Urdu', 'gpt-auto-translate' ),
                'uz' => __( 'Uzbek', 'gpt-auto-translate' ),
                've' => __( 'Venda', 'gpt-auto-translate' ),
                'vi' => __( 'Vietnamese', 'gpt-auto-translate' ),
                'vo' => __( 'Volapük', 'gpt-auto-translate' ),
                'wa' => __( 'Walloon', 'gpt-auto-translate' ),
                'cy' => __( 'Welsh', 'gpt-auto-translate' ),
                'wo' => __( 'Wolof', 'gpt-auto-translate' ),
                'xh' => __( 'Xhosa', 'gpt-auto-translate' ),
                'ii' => __( 'Sichuan Yi', 'gpt-auto-translate' ), // Note: Table lists "Sichuan Yi, Nuosu"
                'yi' => __( 'Yiddish', 'gpt-auto-translate' ),
                'yo' => __( 'Yoruba', 'gpt-auto-translate' ),
                'za' => __( 'Zhuang', 'gpt-auto-translate' ), // Note: Table lists "Zhuang, Chuang"
                'zu' => __( 'Zulu', 'gpt-auto-translate' ),
            // Ajoutez d'autres codes courants si nécessaire
        ];
        return isset($common_names[$code]) ? $common_names[$code] : strtoupper($code);
    }

    /**
     * Nettoie et valide les options soumises avant de les enregistrer.
     *
     * @param array $input Les données brutes soumises par le formulaire.
     * @return array Les données nettoyées à enregistrer.
     */
    public function sanitize_options( $input ) {
        $sanitized_input = [];

        // Nettoyer la clé API (simple nettoyage de champ texte)
        if ( isset( $input['api_key'] ) ) {
            $sanitized_input['api_key'] = sanitize_text_field( trim( $input['api_key'] ) );
        } else {
            $sanitized_input['api_key'] = '';
        }

        // ***NOUVEAU*** Nettoyer le modèle GPT
        if ( isset( $input['gpt_model'] ) ) {
            // Vérifier si le modèle soumis est dans notre liste connue (ou laisser passer si on veut plus de flexibilité?)
            // Pour l'instant, on nettoie juste comme un slug/identifiant
            $sanitized_input['gpt_model'] = sanitize_text_field( trim( $input['gpt_model'] ) );
            // On pourrait ajouter une validation plus stricte ici si besoin
        } else {
                $sanitized_input['gpt_model'] = isset($options_before['gpt_model']) ? $options_before['gpt_model'] : 'gpt-3.5-turbo'; // Garder l'ancien ou le défaut
        }


        // Nettoyer les langues cibles (vérifier que ce sont bien des codes valides)
       // ***MODIFIÉ*** Nettoyer les langues cibles depuis la chaîne
        $sanitized_input['target_languages'] = []; // Le tableau interne des codes
        $sanitized_input['target_languages_str'] = ''; // La chaîne à sauvegarder pour réafficher
        if ( isset( $input['target_languages_str'] ) ) {
            $raw_string = sanitize_textarea_field( $input['target_languages_str'] );
            $sanitized_input['target_languages_str'] = $raw_string; // Sauvegarder la chaîne nettoyée

            $potential_codes = explode( ',', $raw_string ); // Séparer par virgule
            $valid_codes = [];
            foreach ( $potential_codes as $code ) {
                $trimmed_code = trim( $code );
                // Valider basiquement : 2 ou 3 lettres minuscules (ISO 639-1 ou -2)
                if ( !empty($trimmed_code) && preg_match('/^[a-z]{2,3}$/', $trimmed_code) ) {
                    $valid_codes[] = $trimmed_code;
                }
            }
            $sanitized_input['target_languages'] = array_unique( $valid_codes ); // Stocker uniquement les codes valides et uniques
        }
        // Si la chaîne est vide, 'target_languages' sera un tableau vide, ce qui est correct. 
        else {
            $sanitized_input['target_languages'] = [];
        }

        // Nettoyer les types de contenu cibles
        if ( isset( $input['target_post_types'] ) && is_array( $input['target_post_types'] ) ) {
            $public_post_types = get_post_types( [ 'public' => true ], 'names' );
            $sanitized_input['target_post_types'] = [];
            foreach ($input['target_post_types'] as $post_type_slug) {
                $clean_slug = sanitize_key($post_type_slug);
                if ( isset($public_post_types[$clean_slug]) ) { // Vérifie que le CPT existe et est public
                    $sanitized_input['target_post_types'][] = $clean_slug;
                }
            }
        } else {
            $sanitized_input['target_post_types'] = [];
        }


        // Afficher un message de succès standard
        add_settings_error(
            'gpt_auto_translate_options', // Slug de l'option
            'settings_updated',           // ID de l'erreur/message
            __( 'Settings saved.', 'gpt-auto-translate' ), // Message
            'updated'                     // Type de message (updated, error, warning)
        );


        return $sanitized_input;
    }

    // --- METHODES DE L'ETAPE 4 ---

    /**
     * Fonction principale pour traiter la traduction d'un post.
     * Sera appelée (par ex: via AJAX) pour un ID de post donné.
     *
     * @param int $original_post_id ID du post à traduire (source anglaise).
     * @return array Statut de l'opération par langue [lang_code => ['success' => bool, 'message' => string, 'post_id' => int|null]].
     */
    public function process_post_translation( $original_post_id ) {
        $results = [];
        $options = get_option( 'gpt_auto_translate_options' );

        // --- Vérifications initiales ---
        if ( ! $options || empty( $options['api_key'] ) ) {
            return [ 'error' => __( 'API Key not configured.', 'gpt-auto-translate' ) ];
        }
        // MODIFICATION: On a besoin des langues cibles même si l'API n'est pas configurée,
        // car on pourrait vouloir juste mettre à jour les slugs finder_keys.
        // Donc on vérifie la clé API plus tard, avant l'appel API.
        // if ( empty( $options['target_languages'] ) ) {
        //     return [ 'error' => __( 'No target languages configured.', 'gpt-auto-translate' ) ];
        // }
        $target_languages = isset($options['target_languages']) && is_array($options['target_languages']) ? $options['target_languages'] : [];
        $api_key = isset($options['api_key']) ? $options['api_key'] : null;


        $original_post = get_post( $original_post_id );
        if ( ! $original_post || $original_post->post_status === 'auto-draft' ) {
             return [ 'error' => __( 'Invalid source post or post not saved yet.', 'gpt-auto-translate' ) ];
        }

        // Récupérer le contenu original
        $original_title = $original_post->post_title;
        $original_slug = $original_post->post_name; // Slug original
        $original_seo_meta = $this->get_seo_meta( $original_post_id );

        // --- Déterminer si on utilise la logique ACF/Meta ---
        $is_acf_active = function_exists('get_field');
        $use_acf_meta_logic = $is_acf_active;

        $this->log_message("ACF Active=" . ($is_acf_active ? 'Yes' : 'No') . ". Use ACF Logic: " . ($use_acf_meta_logic ? 'Yes' : 'No'));

        // --- LOGIQUE PRINCIPALE ---
        if ($use_acf_meta_logic) {
            $this->log_message("Entering ACF/Meta Logic branch.");

            // --- A : Traduction du contenu principal ([lang]_messages_001) ---
            if (!empty($target_languages)) {
                 // --- Vérification clé API maintenant ---
                 if ( empty( $api_key ) ) {
                     // On ne peut pas traduire sans clé, mais on peut continuer pour mettre à jour finder_keys plus tard.
                     $this->log_message("API Key missing. Skipping content translation, will proceed to check/add translation_pages and update finder_keys.");
                     // Initialiser $results pour éviter les erreurs plus tard
                     foreach($target_languages as $lang_code) {
                         $results[$lang_code] = ['success' => false, 'message' => __('API Key missing, translation skipped.', 'gpt-auto-translate'), 'post_id' => $original_post_id];
                     }
                 } else {
                    // 1. Lire le contenu original depuis ACF 'messages'
                    $acf_messages_key_or_name = 'messages';
                    $original_acf_messages_structured = [];
                    $subfield_meta_key_name = 'meta_key';
                    $subfield_result_name = 'result';

                    if ( have_rows( $acf_messages_key_or_name, $original_post_id ) ) {
                        // ... (boucle have_rows pour lire 'messages' - Code INCHANGÉ) ...
                         while ( have_rows( $acf_messages_key_or_name, $original_post_id ) ) {
                            the_row();
                            $current_meta_key = get_sub_field( $subfield_meta_key_name );
                            $current_result = get_sub_field( $subfield_result_name );
                            $original_acf_messages_structured[] = [
                                $subfield_meta_key_name => $current_meta_key,
                                $subfield_result_name   => $current_result
                            ];
                        }
                        $this->log_message("Successfully read " . count($original_acf_messages_structured) . " rows structure from ACF '{$acf_messages_key_or_name}'.");
                    } else {
                         $this->log_message("ACF field '{$acf_messages_key_or_name}' has no rows on post ID: {$original_post_id}. Content translation will be skipped for this field.");
                         // On ne retourne pas d'erreur ici, car on veut quand même gérer translation_pages et finder_keys
                    }

                    // 2. Lire les métas SEO standard
                     $original_seo_meta = $this->get_seo_meta( $original_post_id );
                     $this->log_message("Read original SEO meta: Title=" . ($original_seo_meta['title'] ?? 'N/A') . ", Desc=" . ($original_seo_meta['description'] ?? 'N/A'));

                    // --- Boucle sur les langues cibles pour la TRADUCTION de contenu ---
                    $this->log_message("Starting content translation loop...");
                    foreach ( $target_languages as $lang_code ) {
                        $language_name = $this->get_language_name( $lang_code );
                        $results[$lang_code] = ['success' => false, 'message' => '', 'post_id' => $original_post_id];
                        $this->log_message("--- Processing content translation for: {$language_name} ({$lang_code}) ---");

                        // Seulement si on a lu du contenu ACF à traduire
                        if (!empty($original_acf_messages_structured) || !empty($original_seo_meta['title']) || !empty($original_seo_meta['description'])) {
                             try {
                                 $translated_data = $this->translate_content_data(
                                    $original_acf_messages_structured, // Peut être vide, la fonction doit gérer
                                    $original_seo_meta,
                                    $lang_code,
                                    $api_key
                                 );
                                 $this->log_message("Content translation API call successful for {$lang_code}.");

                                 // Préparer et MAJ la meta [lang]_messages_001
                                 $lang_meta_key = $lang_code . '_messages_001';
                                 $new_lang_data_structure = $this->prepare_lang_meta_structure(
                                     $original_acf_messages_structured,
                                     $translated_data['acf_content']
                                 );
                                 $meta_updated = update_post_meta( $original_post_id, $lang_meta_key, $new_lang_data_structure );
                                 // Log et vérification simple (code inchangé)
                                 $this->log_message("Updated post meta '{$lang_meta_key}'. Update status: " . ($meta_updated ? 'Success/Changed' : 'Failed/Unchanged'));
                                 if (!$meta_updated && get_post_meta($original_post_id, $lang_meta_key, true) != $new_lang_data_structure) {
                                    throw new Exception("Failed to update post meta '{$lang_meta_key}'.");
                                 }

                                 // Mettre à jour les métas SEO spécifiques si besoin (code inchangé)
                                 // $this->set_language_specific_seo_meta($original_post_id, $lang_code, $translated_data['seo_title'], $translated_data['seo_desc']);

                                 $results[$lang_code]['success'] = true;
                                 $results[$lang_code]['message'] = __('Content Translated (Meta Updated)', 'gpt-auto-translate');

                             } catch ( Exception $e ) {
                                 $error_msg = $e->getMessage();
                                 error_log("[GPT Auto Translate ACF Mode] Content Translation Error for post {$original_post_id} lang {$lang_code}: {$error_msg}");
                                 $results[$lang_code]['message'] = $error_msg;
                                 $results[$lang_code]['success'] = false; // Explicite
                             }
                        } else {
                             $results[$lang_code]['success'] = true; // Considéré comme succès car il n'y avait rien à traduire
                             $results[$lang_code]['message'] = __('No source content found to translate.', 'gpt-auto-translate');
                             $this->log_message("No source content (ACF messages / SEO Meta) found for {$lang_code}, skipping translation.");
                        }

                    } // Fin boucle foreach langue pour traduction contenu
                    $this->log_message("Finished content translation loop.");
                 } // Fin else (clé API existe)

            } else {
                 $this->log_message("No target languages configured in settings. Skipping content translation and 'translation_pages' check.");
                 // On peut quand même lancer la mise à jour de finder_keys basé sur ce qui existe déjà
            }


            // **** B : AJOUT DES LANGUES MANQUANTES DANS 'translation_pages' (Utilise add_row) ****
            $this->log_message("Starting check/add for 'translation_pages' ACF repeater using add_row() on post ID: {$original_post_id}.");

            $acf_translation_pages_key = 'translation_pages';
            $subfield_lang_key = 'language';
            $subfield_title_key = 'title';
            $subfield_slug_key = 'slug';

            // 1. Lire les codes langue déjà présents
            $existing_langs = [];
            if ( have_rows( $acf_translation_pages_key, $original_post_id ) ) {
                while ( have_rows( $acf_translation_pages_key, $original_post_id ) ) {
                    the_row();
                    $lang = get_sub_field($subfield_lang_key);
                    if (!empty($lang)) {
                        $existing_langs[] = $lang;
                    }
                }
            }
            $this->log_message("Languages currently found in '{$acf_translation_pages_key}': " . (!empty($existing_langs) ? implode(', ', $existing_langs) : 'None'));

            // 2. Trouver les langues manquantes
            $missing_langs = array_diff($target_languages, $existing_langs);
            $this->log_message("Target languages from settings: " . (!empty($target_languages) ? implode(', ', $target_languages) : 'None'));
            $this->log_message("Missing languages to potentially add: " . (!empty($missing_langs) ? implode(', ', $missing_langs) : 'None'));

            // 3. Si des langues manquent ET qu'on a une clé API
            if (!empty($missing_langs)) {
                 if (empty($api_key)) {
                    $this->log_message("API Key missing. Cannot translate titles/slugs to add missing languages.");
                 } else {
                     $this->log_message("Adding missing languages using add_row()...");
                     foreach ($missing_langs as $lang_code) {
                         $language_name = $this->get_language_name($lang_code);
                         $this->log_message("--- Preparing to add row for: {$language_name} ({$lang_code}) ---");

                         $translated_title = null;
                         $translated_slug = null; // Initialisation importante

                         // --- Traduire le titre (commun à toutes les langues manquantes) ---
                         try {
                            $title_prompt = "Translate this title accurately to {$language_name} ({$lang_code}): " . $original_title;
                            $api_result_title = $this->call_gpt_api($title_prompt, $lang_code, $api_key);
                            if ($api_result_title !== false && !empty($api_result_title)) {
                                $translated_title = trim($api_result_title, ' "\'');
                                $this->log_message("Translated title for {$lang_code}: {$translated_title}");
                            } else {
                                $this->log_message("Title translation failed or returned empty for {$lang_code}. Using fallback.");
                            }
                         } catch (Exception $e) {
                            $this->log_message("API Error translating title for {$lang_code}: " . $e->getMessage());
                         }
                         if (is_null($translated_title)) {
                              $translated_title = $original_title . " ({$lang_code})"; // Fallback titre
                         }
                         // --- Fin Traduction Titre ---


                         // --- Génération du Slug (Logique spécifique Arabe) ---
                         $latin_languages_for_default_slug = ['es', 'fr', 'de', 'it', 'pt', 'en']; // Added 'en' for completeness, adjust if needed
                         

                         // Check if the current language is NOT one of the Latin languages specified above
                         // For these non-Latin (or complex script) languages, we'll try the API first.
                         if (!in_array($lang_code, $latin_languages_for_default_slug, true)) {

                             $language_name = $this->get_language_name($lang_code); // Get the language name for logging/prompt
                             $this->log_message("Attempting API-generated slug for non-Latin/complex script language: {$language_name} ({$lang_code})...");

                             try {
                                 // Generalized prompt for a short, native-script slug
                                 $slug_prompt_generalized = sprintf(
                                     "Based on the title in %s: \"%s\"\n" .
                                     "Generate a VERY SHORT (ideally 1-3 words), relevant, URL-friendly slug using ONLY letters/characters native to the %s language, numbers (0-9), and hyphens (-).\n" .
                                     "Rules for the slug:\n" .
                                     "1. Use the native script directly (e.g., Cyrillic for Russian, Arabic for Arabic, Hanzi for Chinese, etc.).\n" .
                                     "2. Replace spaces ONLY with hyphens (-).\n" .
                                     "3. Remove ALL other punctuation, symbols, or non-native characters (like English letters if the title is not English, etc.), EXCEPT hyphens and numbers.\n" .
                                     "4. Keep it concise and meaningful in the original language (%s).\n" .
                                     "5. Preferably use lowercase if the script supports it (like Cyrillic), otherwise maintain native case.\n" .
                                     "Provide ONLY the final slug string, nothing else.\n" .
                                     "Example (Russian title 'Моя Новая Статья'): 'моя-новая-статья' or 'новая-статья'.\n" .
                                     "Example (Arabic title 'بحث عن فريق'): 'بحث-فريق' or 'فريق'.\n" .
                                     "Example (Japanese title '新しい記事について'): '新しい記事' or '記事'.",
                                     $language_name,            // Language name for context
                                     $translated_title,         // The title in the target language
                                     $language_name,            // For "native to the X language"
                                     $language_name             // For "meaningful in X"
                                 );

                                 $api_result_slug = $this->call_gpt_api($slug_prompt_generalized, $lang_code, $api_key);

                                 if ($api_result_slug !== false && !empty(trim($api_result_slug))) {
                                     // General cleaning for the API response
                                     $cleaned_slug = trim($api_result_slug);
                                     // Replace various potential separators (spaces, underscores, dots, slashes) with a single hyphen
                                     $cleaned_slug = preg_replace('/[\s_.\/\\\]+/', '-', $cleaned_slug);
                                     // Keep ONLY native letters (any script), numbers, and hyphens.
                                     // \p{L} matches any Unicode letter character.
                                     // \p{N} matches any Unicode number character.
                                     // The 'u' flag is crucial for Unicode matching.
                                     $cleaned_slug = preg_replace('/[^\p{L}\p{N}-]+/u', '', $cleaned_slug);
                                     // Remove leading/trailing hyphens that might result from cleaning
                                     $cleaned_slug = trim($cleaned_slug, '-');
                                     // Optionally, force lowercase IF appropriate (might be language-specific, API should ideally handle it based on prompt)
                                     // Example for scripts where lowercase is standard:
                                     // if (in_array($lang_code, ['ru', 'bg', 'uk', /* other Cyrillic etc. */ ])) {
                                     //    $cleaned_slug = mb_strtolower($cleaned_slug, 'UTF-8');
                                     // }
                                     // For now, rely on API and general cleaning. Avoid forced lowercasing globally as it breaks some scripts.

                                     if (!empty($cleaned_slug)) {
                                         $translated_slug = $cleaned_slug;
                                         $this->log_message("Generated {$language_name} slug via API: {$translated_slug}");
                                     } else {
                                         $this->log_message("API response for {$language_name} slug unusable after cleaning. Original API response: '{$api_result_slug}'");
                                         // $translated_slug remains null, will fall back to default sanitize_title later
                                     }
                                 } else {
                                     $this->log_message("API slug generation failed or returned empty for {$language_name}.");
                                      // $translated_slug remains null, will fall back to default sanitize_title later
                                 }
                             } catch (Exception $e) {
                                 $this->log_message("API Error during {$language_name} slug generation: " . $e->getMessage());
                                 // $translated_slug remains null, will fall back to default sanitize_title later
                             }
                             // If $translated_slug is still null here, the API attempt failed or yielded nothing usable.
                             // The code following this block should handle the fallback (e.g., using sanitize_title).

                         } // --- End non-Latin/complex script API slug logic ---


                         // Final check and logging for the chosen slug
                         $this->log_message("Final slug set for {$lang_code}: {$translated_slug}");

                         // --- Fallback / Génération standard du Slug (pour non-Arabe OU si l'API Arabe a échoué) ---
                         if (is_null($translated_slug)) {
                             if ($lang_code === 'ar') {
                                 $this->log_message("Falling back to sanitize_title() for Arabic slug.");
                             } else {
                                 $this->log_message("Generating slug using sanitize_title() for {$lang_code}.");
                             }
                             // Utilise sanitize_title sur le titre traduit. Pour Arabe, ça donnera l'encodage %.
                             $base_slug_for_lang = sanitize_title($translated_title);
                             if (!empty($base_slug_for_lang)) {
                                 $translated_slug = $base_slug_for_lang;
                                 $this->log_message("Generated slug using sanitize_title(): {$translated_slug}");
                             }
                         }

                         // --- Fallback Ultime (si sanitize_title a échoué aussi) ---
                         if (is_null($translated_slug)) {
                             $this->log_message("Ultimate fallback for slug generation for {$lang_code}.");
                             $translated_slug = sanitize_title($original_title . '-' . $lang_code);
                             if (empty($translated_slug)) {
                                  $translated_slug = $lang_code . '-' . $original_post_id;
                             }
                             $this->log_message("Generated slug using ultimate fallback: {$translated_slug}");
                         }
                         // --- Fin Génération du Slug ---


                         // --- Ajouter la ligne avec add_row() ---
                         $new_row_data = [
                             $subfield_lang_key => $lang_code,
                             $subfield_title_key => $translated_title,
                             $subfield_slug_key => $translated_slug, // Utilise le slug final déterminé
                         ];

                         $added_row_index = add_row($acf_translation_pages_key, $new_row_data, $original_post_id);
                         if ($added_row_index) {
                             $this->log_message("Successfully added row for {$lang_code} using add_row(). Index: {$added_row_index}");
                         } else {
                             $this->log_message("Failed to add row for {$lang_code} using add_row().");
                             if(isset($results[$lang_code])) {
                                 $results[$lang_code]['message'] .= ($results[$lang_code]['message'] ? ' | ' : '') . 'Failed to add translation page entry.';
                             }
                         }
                         // --- Fin Ajouter la ligne ---

                     } // Fin boucle foreach missing_langs
                 } // Fin else (clé API existe)
            } else {
                $this->log_message("No missing languages found in '{$acf_translation_pages_key}'. No rows added.");
            }
            // **** FIN SECTION B (utilisant add_row) ****


            // **** C : MISE À JOUR DES CHAMPS finder_keys ET finder_keys_meta ****
            // Cette section reste INCHANGÉE, elle lira le champ mis à jour par add_row()
             $this->log_message("Starting update for 'finder_keys' (ACF Textarea) and 'finder_keys_meta' (WP Meta) based on FINAL '{$acf_translation_pages_key}' content.");
             // ... (code identique à la version précédente pour la section C) ...
              // Relire le champ translation_pages MAINTENANT pour inclure les ajouts potentiels
             $acf_finder_keys_key = 'finder_keys';
             $wp_meta_finder_keys_key = 'finder_keys_meta';
             // $subfield_slug_key est déjà défini

             $all_slugs = [];
             if ( have_rows( $acf_translation_pages_key, $original_post_id ) ) {
                 $this->log_message("Reading final slugs from '{$acf_translation_pages_key}'.");
                 while ( have_rows( $acf_translation_pages_key, $original_post_id ) ) {
                     the_row();
                     $slug = get_sub_field( $subfield_slug_key );
                     if ( ! empty( $slug ) && is_string($slug) ) {
                         $all_slugs[] = trim( $slug );
                     }
                 }
                 $this->log_message("Collected " . count($all_slugs) . " final slugs.");
             } else {
                 $this->log_message("Final '{$acf_translation_pages_key}' field has no rows. Finder keys will be emptied.");
             }

             // Rendre uniques
             $unique_slugs = array_unique( $all_slugs );
             $this->log_message("Unique final slugs count: " . count($unique_slugs) . ". Slugs: " . implode(', ', $unique_slugs));

             // MàJ ACF finder_keys (textarea)
             $finder_keys_string = implode( "\n", $unique_slugs );
             $update_acf_finder_status = update_field( $acf_finder_keys_key, $finder_keys_string, $original_post_id );
             // Log ACF finder_keys (inchangé)
              if ( $update_acf_finder_status ) {
                 $this->log_message("Successfully updated ACF field '{$acf_finder_keys_key}'.");
             } else {
                 $current_acf_value = get_field($acf_finder_keys_key, $original_post_id);
                 $normalized_current_acf = str_replace("\r\n", "\n", $current_acf_value ?? '');
                 $normalized_new_acf = str_replace("\r\n", "\n", $finder_keys_string);
                 if ($normalized_current_acf === $normalized_new_acf) {
                      $this->log_message("ACF field '{$acf_finder_keys_key}' value was already up-to-date.");
                 } else {
                     $this->log_message("Failed to update ACF field '{$acf_finder_keys_key}', or value did not change.");
                 }
             }


             // MàJ WP finder_keys_meta (tableau)
             $update_meta_status = update_post_meta( $original_post_id, $wp_meta_finder_keys_key, $unique_slugs );
              // Log WP finder_keys_meta (inchangé)
             if ( $update_meta_status ) {
                  $this->log_message("Successfully updated WP meta field '{$wp_meta_finder_keys_key}' (returned true/meta_id).");
             } else {
                  $current_meta_value = get_post_meta($original_post_id, $wp_meta_finder_keys_key, true);
                  if ($current_meta_value == $unique_slugs) {
                      $this->log_message("WP meta field '{$wp_meta_finder_keys_key}' value was already up-to-date.");
                  } else {
                     $this->log_message("Failed to update WP meta field '{$wp_meta_finder_keys_key}'.");
                  }
             }
            // **** FIN SECTION C ****


        } else {
            // --- Mode Original V1 --- (inchangé)
            $this->log_message("Error: Plugin is not running in ACF/Meta mode for the current theme, or ACF is not active.");
            return ['error' => __('This version requires the target theme and ACF, or the V1 logic needs to be restored.', 'gpt-auto-translate')];
        }

        $this->log_message("Translation & Meta update process finished for post ID: {$original_post_id}. Results: " . json_encode($results));

        return $results;
    }



    
    /**
     * Appelle l'API GPT pour traduire un texte.
     *
     * @param string $text Texte à traduire (ou contenu HTML).
     * @param string $target_lang_code Code de la langue cible (ex: 'es').
     * @param string $api_key Clé API GPT.
     * @param string|null $system_prompt Prompt système personnalisé à utiliser. Si null, un prompt par défaut sera utilisé.
     * @return string|false Texte traduit ou false en cas d'erreur.
     */
    private function call_gpt_api( $text, $target_lang_code, $api_key, $system_prompt = null ) { // Ajout de $system_prompt
        $options = get_option( 'gpt_auto_translate_options' );
        $model = isset( $options['gpt_model'] ) && !empty($options['gpt_model']) ? $options['gpt_model'] : 'gpt-3.5-turbo';
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $language_name = $this->get_language_name($target_lang_code);

        // ***MODIFIÉ*** : Définir le prompt système final
        if ( $system_prompt === null ) {
             // Prompt système par défaut pour les traductions simples (titre, metas)
             $final_system_prompt = sprintf(
                "You are a highly skilled translation assistant. Translate the following text accurately and fluently into %s (%s). Maintain the original meaning and tone. Do not add any extra commentary, just provide the translation.",
                $language_name,
                $target_lang_code
            );
        } else {
            // Utiliser le prompt système fourni (ex: pour le HTML)
             // Remplacer les placeholders dans le prompt système fourni
             $final_system_prompt = str_replace('{language_name}', $language_name, $system_prompt);
             $final_system_prompt = str_replace('{language_code}', $target_lang_code, $final_system_prompt);
        }

        // Construire le payload pour l'API
        $messages = [
            [
                'role' => 'system',
                'content' => $final_system_prompt // Utilise le prompt système déterminé
            ],
            [
                'role' => 'user',
                'content' => $text // Le texte brut ou le HTML à traduire
            ]
        ];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.5, // Peut-être réduire un peu pour le HTML pour être plus déterministe? A tester.
            'max_tokens' => 3000, // Augmenter potentiellement pour les contenus longs
        ];

        $args = [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( $body ),
            'timeout' => 90, // Augmenter encore un peu le timeout pour les contenus longs
        ];

        $response = wp_remote_post( $api_url, $args );

        // --- Gestion de la réponse (reste identique) ---
        if ( is_wp_error( $response ) ) {
            error_log( '[GPT Auto Translate] API Call WP_Error: ' . $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code >= 300 || $response_code < 200 ) {
             $error_message = isset($data['error']['message']) ? $data['error']['message'] : $response_body;
             error_log( '[GPT Auto Translate] API HTTP Error: Code ' . $response_code . ' - ' . $error_message );
             // Tenter de retourner un message plus utile pour le debug
             // return false; // Ancienne version
             throw new Exception("API HTTP Error ({$response_code}): " . $error_message); // Lève une exception
        }


        if ( isset( $data['choices'][0]['message']['content'] ) ) {
             // Ne PAS trimer aveuglément ici pour le contenu HTML
            // return trim( $data['choices'][0]['message']['content'] ); // Ancienne version
            return $data['choices'][0]['message']['content'];
        } elseif (isset($data['error'])) { // Gestion plus explicite des erreurs API retournées dans le JSON
            error_log('[GPT Auto Translate] API Error in Response Body: ' . $data['error']['message']);
            throw new Exception("API Error: " . $data['error']['message']);
        }
         else {
             error_log( '[GPT Auto Translate] API Error: Unexpected response format. Body: ' . $response_body );
             // return false; // Ancienne version
             throw new Exception("API Error: Unexpected response format."); // Lève une exception
        }
    }

    /**
     * Récupère les métadonnées SEO (titre, description) d'un post.
     * Gère Yoast SEO et Rank Math pour l'instant.
     *
     * @param int $post_id ID du post.
     * @return array ['title' => string|null, 'description' => string|null]
     */
    private function get_seo_meta( $post_id ) {
        $meta = ['title' => null, 'description' => null];

        // Essayer Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) { // Vérifie si Yoast est actif
            $meta['title'] = get_post_meta( $post_id, '_yoast_wpseo_title', true );
            $meta['description'] = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        }
        // Si Yoast n'a rien ou n'est pas actif, essayer Rank Math
        if ( empty($meta['title']) && empty($meta['description']) && class_exists( 'RankMath' ) ) {
             $meta['title'] = get_post_meta( $post_id, 'rank_math_title', true );
             $meta['description'] = get_post_meta( $post_id, 'rank_math_description', true );
        }
        // Ajouter d'autres plugins SEO ici si nécessaire (SEOPress, etc.)

        return $meta;
    }

    /**
     * Enregistre les métadonnées SEO (titre, description) pour un post.
     * Gère Yoast SEO et Rank Math pour l'instant.
     *
     * @param int $post_id ID du post.
     * @param string $meta_title Titre SEO traduit.
     * @param string $meta_description Description SEO traduite.
     */
    private function set_seo_meta( $post_id, $meta_title, $meta_description ) {
         if (empty($meta_title) && empty($meta_description)) {
             return; // Ne rien faire si les deux sont vides
         }

        // Essayer Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            if (!empty($meta_title)) update_post_meta( $post_id, '_yoast_wpseo_title', $meta_title );
             if (!empty($meta_description)) update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
             return; // Supposer qu'on n'utilise qu'un seul plugin SEO majeur
        }

        // Essayer Rank Math
        if ( class_exists( 'RankMath' ) ) {
             if (!empty($meta_title)) update_post_meta( $post_id, 'rank_math_title', $meta_title );
             if (!empty($meta_description)) update_post_meta( $post_id, 'rank_math_description', $meta_description );
             return;
        }
         // Ajouter d'autres plugins SEO ici si nécessaire
    }

    /**
     * Charge les scripts et styles nécessaires pour l'administration.
     * Limité aux écrans d'édition des types de contenu sélectionnés.
     *
     * @param string $hook Suffixe de la page admin actuelle (ex: 'post.php', 'post-new.php').
     */
    public function enqueue_admin_scripts( $hook ) {
        global $post; // Accéder à l'objet $post global

        // Cibles : pages d'édition de post existant ou de nouveau post
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return; // Ne pas charger sur d'autres pages admin
        }

        // Vérifier si le type de contenu est l'un de ceux ciblés par notre plugin
        $options = get_option( 'gpt_auto_translate_options' );
        $target_post_types = isset( $options['target_post_types'] ) ? $options['target_post_types'] : [];

        // S'assurer qu'on a un objet $post et que son type est dans notre liste
        if ( ! $post || ! in_array( $post->post_type, $target_post_types ) ) {
            return;
        }

        // Charger notre script JS
        wp_enqueue_script(
            'gpt-auto-translate-admin',                     // Handle unique
            GPT_AUTO_TRANSLATE_URL . 'js/admin-translate.js', // Chemin vers le fichier JS
            [ 'jquery' ],                                   // Dépendances (jQuery est nécessaire)
            GPT_AUTO_TRANSLATE_VERSION,                     // Version (pour le cache busting)
            true                                            // Charger dans le footer
        );

        // Passer des données PHP vers JavaScript (traductions, nonce action, etc.)
        // Très utile pour les chaînes traduisibles et les infos dynamiques
        wp_localize_script(
            'gpt-auto-translate-admin', // Le handle du script auquel attacher les données
            'gptTranslateData',         // Nom de l'objet JavaScript qui contiendra les données
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ), // URL standard pour AJAX dans WP
                'nonce' => wp_create_nonce( 'gpt_translate_action_' . $post->ID ), // On pourrait passer le nonce ici, mais on le lit déjà du champ caché. Gardons le champ caché pour l'instant.
                'translating_text' => __( 'Translating, please wait...', 'gpt-auto-translate' ),
                'success_text' => __( 'Translation process completed.', 'gpt-auto-translate' ),
                'error_text' => __( 'An error occurred during translation.', 'gpt-auto-translate' ),
                'refresh_notice' => __('Please reload the page to see updated status and edit links.', 'gpt-auto-translate'),
            ]
        );

        // On pourrait aussi charger un CSS ici si besoin avec wp_enqueue_style()
        // wp_enqueue_style('gpt-auto-translate-admin-css', GPT_AUTO_TRANSLATE_URL . 'css/admin.css');
    }

    /**
     * Gère la requête AJAX pour lancer la traduction d'un post.
     */
    public function handle_ajax_translation_request() {
        // 1. Vérification du Nonce
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        // L'action du nonce doit correspondre à celle utilisée dans wp_nonce_field()
        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'gpt_translate_action_' . $post_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'gpt-auto-translate' ), 403 ); // 403 Forbidden
        }

        // 2. Vérification des Permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to translate this post.', 'gpt-auto-translate' ), 403 );
        }

        // 3. Appeler la logique de traduction principale
        try {
            $results = $this->process_post_translation( $post_id );

            // Vérifier si une erreur globale a été retournée (ex: clé API manquante)
            if(isset($results['error'])) {
                wp_send_json_error( $results['error'] ); // Le message d'erreur est déjà traduit si besoin
            }

            // Si tout s'est bien passé (même si certaines langues ont échoué individuellement)
            // $results contient le détail par langue ['es' => ['success'=>bool, 'message'=>'...', 'post_id'=>...], ...]
            wp_send_json_success( $results );

        } catch ( Exception $e ) {
            // Attraper toute exception imprévue qui pourrait survenir dans process_post_translation
            error_log('[GPT Auto Translate] AJAX Handler Exception: ' . $e->getMessage());
            wp_send_json_error( __( 'An unexpected error occurred.', 'gpt-auto-translate' ) );
        }

        // Note: wp_send_json_success/error termine l'exécution du script PHP (équivalent de die()).
    }

    /**
     * Ajoute les balises <link rel="alternate" hreflang="..."> dans le <head>
     * des pages singulières qui ont des traductions.
     * Ne fait rien si Polylang est actif (car il le gère).
     */
    public function add_hreflang_tags() {
        // Ne rien faire si Polylang est actif et gère les langues
        if ( function_exists( 'pll_the_languages' ) ) {
            return;
        }

        // Vérifier si on est sur une page/post singulier
        if ( ! is_singular() ) {
            return;
        }

        $current_post_id = get_queried_object_id();
        $original_post_id = $current_post_id; // Par défaut
        $translations = []; // [lang_code => post_id]

        // Vérifier si le post actuel est une traduction
        $is_translation = get_post_meta( $current_post_id, '_gpt_original_post_id', true );
        if ( $is_translation ) {
            $original_post_id = intval( $is_translation );
        }

        // Récupérer la map des traductions depuis le post original
        $translation_map = get_post_meta( $original_post_id, '_gpt_translation_ids', true );
        $translation_map = is_array( $translation_map ) ? $translation_map : [];

        // Construire la liste complète [lang_code => post_id]
        // Ajouter l'original (supposer 'en' comme langue source par défaut)
        // On pourrait rendre la langue source configurable plus tard
        $translations['en'] = $original_post_id;

        // Ajouter les traductions valides
        foreach ( $translation_map as $lang_code => $t_id ) {
            $t_post = get_post( $t_id );
            if ( $t_post && $t_post->post_status === 'publish' ) { // Uniquement les traductions publiées
                $translations[ sanitize_key($lang_code) ] = $t_id;
            }
        }

        // Si on a moins de 2 langues (original + 1 trad), pas besoin de hreflang
        if ( count( $translations ) < 2 ) {
            return;
        }

        // Générer les balises
        echo "\n<!-- GPT Auto Translate hreflang tags -->\n";
        foreach ( $translations as $lang_code => $post_id ) {
            $url = get_permalink( $post_id );
            if ($url) {
                echo '<link rel="alternate" hreflang="' . esc_attr( $lang_code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
            }
        }
        // Ajouter x-default pointant vers l'anglais (ou la langue source)
        if (isset($translations['en'])) {
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( get_permalink($translations['en']) ) . '" />' . "\n";
        }
        echo "<!-- End GPT Auto Translate hreflang tags -->\n";
    }

    /**
     * Génère le HTML pour le sélecteur de langue via le shortcode [gpt_language_switcher].
     * Peut être utilisé si un plugin comme Polylang n'est pas utilisé ou si son sélecteur ne convient pas.
     *
     * @param array $atts Attributs du shortcode (non utilisés ici pour l'instant).
     * @return string HTML du sélecteur de langue ou chaîne vide.
     */
    public function render_language_switcher_shortcode( $atts = [] ) {
        // Shortcode non pertinent hors des pages singulières
        if ( ! is_singular() ) {
            return '';
        }

        // Si Polylang est actif, on pourrait suggérer d'utiliser son propre sélecteur
        // if ( function_exists( 'pll_the_languages' ) ) {
        //     return '<!-- Polylang is active, consider using its language switcher. -->';
        // }

        $current_post_id = get_queried_object_id();
        $original_post_id = $current_post_id;
        $current_lang = 'en'; // Langue source par défaut
        $translations = []; // [lang_code => post_id]

        // Vérifier si le post actuel est une traduction
        $original_id_meta = get_post_meta( $current_post_id, '_gpt_original_post_id', true );
        if ( $original_id_meta ) {
            $original_post_id = intval( $original_id_meta );
            $current_lang = get_post_meta( $current_post_id, '_gpt_language', true ) ?: 'unknown';
        }

        // Récupérer la map des traductions depuis le post original
        $translation_map = get_post_meta( $original_post_id, '_gpt_translation_ids', true );
        $translation_map = is_array( $translation_map ) ? $translation_map : [];

        // Construire la liste complète [lang_code => post_id] incluant l'original
        $translations['en'] = $original_post_id;
        foreach ( $translation_map as $lang_code => $t_id ) {
            $t_post = get_post( $t_id );
            if ( $t_post && $t_post->post_status === 'publish' ) {
                $translations[ sanitize_key($lang_code) ] = $t_id;
            }
        }

        // Si moins de 2 langues, pas besoin de sélecteur
        if ( count( $translations ) < 2 ) {
            return '';
        }

        // Construction du HTML
        $output = '<div class="gpt-language-switcher"><ul>';

        foreach ( $translations as $lang_code => $post_id ) {
            $language_name = $this->get_language_name( $lang_code ); // Notre helper
            $url = get_permalink( $post_id );

            if ($url) {
                $output .= '<li class="lang-item lang-item-' . esc_attr($lang_code);
                if ( $lang_code === $current_lang ) {
                    $output .= ' current-lang">'; // Classe pour la langue actuelle
                    $output .= esc_html( $language_name ); // Juste le nom, pas de lien
                } else {
                    $output .= '">';
                    $output .= '<a href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $lang_code ) . '" rel="alternate">' . esc_html( $language_name ) . '</a>';
                }
                $output .= '</li>';
            }
        }

        $output .= '</ul></div>';

        return $output;
    }

    /**
     * Affiche le champ HTML pour sélectionner le modèle GPT.
     */
    public function render_gpt_model_field() {
        $options = get_option( 'gpt_auto_translate_options' );
        $current_model = isset( $options['gpt_model'] ) ? $options['gpt_model'] : 'gpt-3.5-turbo'; // Défaut

        // Liste des modèles courants (peut être mise à jour)
        $available_models = [
            'gpt-4o' => 'GPT-4o (Latest)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Default)',
        ];

        ?>
        <select name='gpt_auto_translate_options[gpt_model]' id='gpt_model'>
            <?php foreach ($available_models as $model_id => $model_name): ?>
                <option value='<?php echo esc_attr( $model_id ); ?>' <?php selected( $current_model, $model_id ); ?>>
                    <?php echo esc_html( $model_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php _e( 'Select the GPT model to use for translations. Ensure your API key has access to the selected model.', 'gpt-auto-translate' ); ?>
            <?php _e('More powerful models may yield better results but cost more.', 'gpt-auto-translate'); ?>
        </p>
        <?php
    }

     /**
     * Affiche le champ texte pour saisir les codes de langue cibles.
     */
    public function render_target_languages_field() {
        $options = get_option( 'gpt_auto_translate_options' );
        // On récupère la chaîne brute sauvegardée pour l'afficher dans le textarea
        $languages_str = isset( $options['target_languages_str'] ) ? $options['target_languages_str'] : '';

        ?>
        <textarea name='gpt_auto_translate_options[target_languages_str]' id='target_languages_str' class='large-text' rows='3'><?php echo esc_textarea( $languages_str ); ?></textarea>
        <p class="description">
            <?php _e( 'Enter the target language codes, separated by commas (e.g., <code>es, fr, de, ru, ja</code>).', 'gpt-auto-translate' ); ?>
            <?php _e( 'Use standard ISO 639-1 codes.', 'gpt-auto-translate' ); ?>
            <a href="https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes" target="_blank"><?php _e('List of codes', 'gpt-auto-translate'); ?></a>
        </p>
        <?php
    }

    /**
     * Retourne le prompt système détaillé pour la traduction de contenu HTML.
     * @return string
     */
    private function get_html_translation_system_prompt() {
            // Placeholders {language_name} et {language_code} seront remplacés dans call_gpt_api
                return <<<PROMPT
            You are an expert HTML translator. Your task is to translate the text content within the following HTML document into {language_name} ({language_code}).

            RULES:
            1.  **PRESERVE HTML STRUCTURE:** Maintain the exact same HTML tags, structure, and attributes as the original document. DO NOT change, add, or remove any HTML tags or attributes (like class, id, style, etc.), except as noted below.
            2.  **TRANSLATE TEXT ONLY:** Only translate the actual text content found between HTML tags (e.g., inside <p>, <li>, <h1>, <span>) and text nodes directly within the body.
            3.  **DO NOT TRANSLATE TAGS/ATTRIBUTES NAMES:** Never translate the names of HTML tags (e.g., `<p>`, `<div>`, `<span>`) or attribute names (e.g., `class=`, `href=`).
            4.  **TRANSLATE SPECIFIC ATTRIBUTES VALUES:** You SHOULD translate the TEXT VALUE of the 'alt' attribute in <img> tags and the 'title' attribute in <a> and other relevant tags. Leave values of other attributes like 'href', 'src', 'class', 'id', 'style' completely untouched.
            5.  **HANDLE HTML ENTITIES:** Preserve HTML entities like  , &, <, > exactly as they are in the original.
            6.  **IGNORE CODE/PRE TAGS:** If you encounter text within <code> or <pre> tags, leave that specific text completely untranslated.
            7.  **WORDPRESS SHORTCODES:** If you see content like `[shortcode attr="value"]...[/shortcode]` or `[shortcode]`, leave the entire shortcode block (including attributes and enclosed content if any) exactly as it is, without translating any part of it. Treat `<!-- wp:shortcode -->...<!-- /wp:shortcode -->` blocks the same way.
            8.  **IGNORE HTML COMMENTS:** Preserve HTML comments (`<!-- ... -->`) exactly as they are, without translating their content, unless they are WordPress block delimiters (`<!-- wp:... -->` or `<!-- /wp:... -->`) which should ideally be preserved but their content should not be translated. Handle Gutenberg block comments carefully. If possible, ignore them or preserve them exactly.
            9.  **VALID OUTPUT:** Return ONLY the fully translated HTML document. The output must be valid HTML. Do not include any extra text, explanations, apologies, or markdown formatting like ```html ... ``` around the code. Just the raw translated HTML.
        PROMPT;
    }

    // --- NOUVELLES FONCTIONS HELPER POUR LA LOGIQUE V2 ---

    /**
     * Traduit les différentes parties du contenu (ACF, SEO Meta).
     * @param array $original_acf_messages Tableau lu depuis le champ ACF 'messages'.
     * @param array $original_seo_meta Tableau ['title' => ..., 'description' => ...].
     * @param string $lang_code Code langue cible.
     * @param string $api_key Clé API.
     * @return array ['acf_content' => [meta_key => traduit], 'seo_title' => traduit, 'seo_desc' => traduit]
     * @throws Exception En cas d'erreur API majeure.
     */
    private function translate_content_data($original_acf_messages, $original_seo_meta, $lang_code, $api_key) {
        $translated_data = [
            'acf_content' => [],
            'seo_title'   => '',
            'seo_desc'    => '',
        ];
        $html_system_prompt = $this->get_html_translation_system_prompt();
        $this->log_message("html_system_prompt: {$html_system_prompt}");

        $meta_keys_to_translate = ['main_content', 'vision', 'faqs', 'meta_description', 'promotion', 'slots_description', 'short_description', 'page_title', 'introduction', 'playing_with_crypto', 'contact_details', 'faqs_1', 'faqs_2', 'faqs_3', 'faqs_4', 'faqs_5', 'faqs_6', 'glossary_1', 'glossary_2', 'glossary_3', 'glossary_test', 'news_1', 'news_2', 'news_3', 'terms' ]; // **LISTE FINALE**

        // Noms/clés des sous-champs ACF
        $acf_meta_key_subkey = 'meta_key'; // Ou 'field_640722bfc16b8'
        $acf_result_subkey = 'result';   // Ou 'field_6409660d4ba07'

        // Traduire ACF 'messages'
         if (is_array($original_acf_messages)) {

            foreach ($original_acf_messages as $row_data) {
                $meta_key = isset($row_data[$acf_meta_key_subkey]) ? $row_data[$acf_meta_key_subkey] : null;
                $original_result = isset($row_data[$acf_result_subkey]) ? $row_data[$acf_result_subkey] : '';
                $this->log_message(" meta_key ==> {$meta_key}  original_result ==> {$original_result}");

                $this->log_message("DEBUG: meta_key = '" . $meta_key . "'"); // Check for quotes around it
                $this->log_message("DEBUG: meta_keys_to_translate = " . print_r($meta_keys_to_translate, true));
                $is_in_array = in_array($meta_key, $meta_keys_to_translate);
                $this->log_message("DEBUG: in_array result = " . ($is_in_array ? 'TRUE' : 'FALSE'));


                if (($meta_key && in_array($meta_key, $meta_keys_to_translate)) && !empty($original_result)) {
                    $this->log_message("is_array: meta_key ==> {$meta_key} {$original_result}");

                    $content_to_translate = preg_replace('/<!--\s*(?:\/)?wp:.*?-->/s', '', $original_result);
                    $translated_text = $this->call_gpt_api($content_to_translate, $lang_code, $api_key, $html_system_prompt);
                    $this->log_message("Content: {$translated_text}");

                    if ($translated_text !== false) {
                        $translated_data['acf_content'][$meta_key] = $translated_text;
                    } else {
                        // Lance une exception pour arrêter le processus pour cette langue si une traduction ACF échoue ? Ou juste log ?
                         throw new Exception("API Error translating ACF field '{$meta_key}' for lang {$lang_code}");
                    }
                }
            }
        }

        // Traduire Meta Title SEO (si présent)
        if (!empty($original_seo_meta['title'])) {
            $meta_title_user_prompt = "Translate this SEO meta title: " . $original_seo_meta['title'];
            $translated_title = $this->call_gpt_api($meta_title_user_prompt, $lang_code, $api_key);
            if ($translated_title === false) {
                 throw new Exception("API Error translating SEO title for lang {$lang_code}");
            }
            $translated_data['seo_title'] = trim($translated_title, ' "\'');
        }

         // Traduire Meta Description SEO (si présent)
        if (!empty($original_seo_meta['description'])) {
             $meta_desc_user_prompt = "Translate this SEO meta description: " . $original_seo_meta['description'];
            $translated_desc = $this->call_gpt_api($meta_desc_user_prompt, $lang_code, $api_key);
            if ($translated_desc === false) {
                 throw new Exception("API Error translating SEO description for lang {$lang_code}");
            }
            $translated_data['seo_desc'] = trim($translated_desc, ' "\'');
        }

        return $translated_data;
    }

         /**
      * Prépare la structure de données pour la meta [lang]_messages_001.
      * @param array $original_acf_messages Structure ACF 'messages' originale.
      * @param array $translated_acf_content Tableau [meta_key => texte traduit].
      * @return array Structure prête pour update_post_meta.
      */
      private function prepare_lang_meta_structure($original_acf_messages, $translated_acf_content) {
        $new_lang_data_structure = ['data' => []];
        $acf_meta_key_subkey = 'meta_key'; // Ou clé ACF
        $acf_result_subkey = 'result';   // Ou clé ACF

        if (is_array($original_acf_messages)) {
            foreach ($original_acf_messages as $row_data) {
                $meta_key = isset($row_data[$acf_meta_key_subkey]) ? $row_data[$acf_meta_key_subkey] : null;
                if (!$meta_key) continue;

                $original_text_for_key = isset($row_data[$acf_result_subkey]) ? $row_data[$acf_result_subkey] : '';
                $translated_text = isset($translated_acf_content[$meta_key]) ? $translated_acf_content[$meta_key] : null;

                // Ajoute l'entrée si on a une traduction pour cette clé, ou même si on n'en a pas ?
                // Décidons d'ajouter seulement si traduit pour l'instant.
                if ($translated_text !== null) {
                    $new_lang_data_structure['data'][$meta_key] = [
                        'en_content' => $original_text_for_key, // Inclure si le thème l'utilise
                        'translated' => true,
                        'content'    => $translated_text
                    ];
                }
                // Si on voulait inclure même les non-traduits :
                // else {
                //      $new_lang_data_structure['data'][$meta_key] = [
                //         'en_content' => $original_text_for_key,
                //         'translated' => false,
                //         'content'    => $original_text_for_key // ou '' ?
                //     ];
                // }
            }
        }
        return $new_lang_data_structure;
    }

    /**
     * Met à jour le champ ACF 'translation_pages' du thème sur le post original.
     * @param int $original_post_id ID du post original.
     * @param string $lang_code Code langue de la traduction effectuée.
     * @param string $translated_title Titre (peut être l'original ou traduit).
     * @param string $original_slug Slug du post original (le thème ne semble pas utiliser de slug traduit).
     */
    private function update_theme_translation_pages_acf($original_post_id, $lang_code, $translated_title, $original_slug) {
        $acf_translation_pages_key = 'translation_pages'; // Ou field_667a90ae70cac
        $subfield_lang_key = 'language'; // Ou field_667a90ba70cad
        $subfield_title_key = 'title';   // Ou field_667a90d370cae
        $subfield_slug_key = 'slug';     // Ou field_667a90d970caf

        $current_translation_pages = get_field($acf_translation_pages_key, $original_post_id);
        $current_translation_pages = is_array($current_translation_pages) ? $current_translation_pages : [];

        $updated_translation_pages = [];
        $found_lang = false;

        foreach ($current_translation_pages as $row) {
            $current_row_lang = isset($row[$subfield_lang_key]) ? $row[$subfield_lang_key] : null;

            if ($current_row_lang === $lang_code) {
                // Met à jour la ligne existante (le titre peut changer, le slug reste celui de l'original)
                $row[$subfield_title_key] = $translated_title; // Utiliser le titre traduit? Ou garder celui du thème ? Utilisons le traduit.
                $row[$subfield_slug_key] = $original_slug; // Le slug pointe toujours vers le post original
                $found_lang = true;
            }
            $updated_translation_pages[] = $row;
        }

        if (!$found_lang) {
            $updated_translation_pages[] = [
                $subfield_lang_key => $lang_code,
                $subfield_title_key => $translated_title, // Titre traduit
                $subfield_slug_key => $original_slug,   // Slug original
            ];
        }

        // Mettre à jour le champ ACF sur le post original
        update_field($acf_translation_pages_key, $updated_translation_pages, $original_post_id);
         $this->log_message("Updated '{$acf_translation_pages_key}' on post {$original_post_id} for lang {$lang_code}."); // Ajout Log
    }

     /**
      * Fonction simple de logging (si WP_DEBUG et WP_DEBUG_LOG sont activés)
      * @param string $message Message à logguer.
      */
     private function log_message( $message ) {
         if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
             error_log( '[GPT Auto Translate V2] ' . $message );
         }
     }

     // Inside the Gpt_Auto_Translate class

    /**
     * Registers complex ACF fields manually for the REST API using register_rest_field.
     */
    public function register_custom_acf_rest_fields() {

        // Check if ACF core functions exist
         if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
            $this->log_message( 'ACF core functions not found. Skipping manual ACF REST field registration.' );
            return;
        }

        $options           = get_option( 'gpt_auto_translate_options' );
        $target_post_types = isset( $options['target_post_types'] ) && is_array( $options['target_post_types'] )
                            ? $options['target_post_types']
                            : [];

        if ( empty( $target_post_types ) ) {
             $this->log_message( 'No target post types configured. Skipping manual ACF REST field registration.' );
             return;
        }
        $this->log_message( 'Manual ACF REST Registration: Targeting post types: ' . implode( ', ', $target_post_types ) );

        $acf_fields_to_register = [
            'messages' => [
                'api_field_name' => 'acf_messages', // <<< RESTORE THIS
                // Schema can be defined here OR fetched dynamically later
                // 'schema' => $this->get_messages_repeater_schema(), // Optional: Keep schema here
            ],
            'translation_pages' => [
                'api_field_name' => 'acf_translation_pages', // <<< RESTORE THIS
                 // 'schema' => $this->get_translation_pages_repeater_schema(), // Optional: Keep schema here
            ],
        ];

        // --- Pre-generate maps for known repeaters ---
        // Cache them locally for the duration of this function call
        $repeater_maps_cache = [];
        foreach (array_keys($acf_fields_to_register) as $acf_field_name) {
            $maps = $this->get_dynamic_acf_repeater_maps($acf_field_name);
            if ($maps) {
                 $repeater_maps_cache[$acf_field_name] = $maps;
                 $this->log_message("Successfully generated dynamic maps for repeater: {$acf_field_name}");
            } else {
                 $this->log_message("Failed to generate dynamic maps for repeater: {$acf_field_name}. REST exposure might fail.");
                 // Decide if you want to skip registration or proceed with potential errors
            }
        }


        foreach ( $acf_fields_to_register as $acf_field_name => $config ) {
            $api_field_name = $config['api_field_name'];
             // Use the dynamically generated schema from the config
             $schema = isset( $config['schema'] ) ? $config['schema'] : null;
             if (is_null($schema)) {
                 // Dynamically call the schema generation method if not directly provided
                 $schema_method_name = 'get_' . $acf_field_name . '_repeater_schema'; // e.g., get_messages_repeater_schema
                 if (method_exists($this, $schema_method_name)) {
                     $schema = $this->$schema_method_name();
                 } else {
                     $this->log_message("Warning: Schema generation method '{$schema_method_name}' not found for '{$acf_field_name}'.");
                     // Provide a default basic schema or skip registration
                     $schema = ['type' => 'array', 'description' => "ACF Repeater: {$acf_field_name}"];
                 }
             }


             // Check if we successfully generated maps for this specific repeater
             if (!isset($repeater_maps_cache[$acf_field_name])) {
                  $this->log_message("Skipping REST registration for '{$api_field_name}' (ACF: '{$acf_field_name}') because dynamic maps could not be generated.");
                  continue; // Skip registering this field if maps failed
             }

             // Pass the SPECIFIC maps for this repeater into the callbacks
             $current_key_to_name_map = $repeater_maps_cache[$acf_field_name]['key_to_name'];
             $current_name_to_key_map = $repeater_maps_cache[$acf_field_name]['name_to_key'];

            register_rest_field(
                $target_post_types,
                $api_field_name,
                [
                    // --- GET Callback ---
                    'get_callback' => function( $object, $field_name_from_api, $request ) use ( $acf_field_name, $current_key_to_name_map ) { // Pass key_to_name map
                        if ( isset( $object['id'] ) && function_exists( 'get_field' ) ) {
                            $raw_data = get_field( $acf_field_name, $object['id'], false );

                             if ( is_array( $raw_data ) && ! empty( $raw_data ) ) {
                                $processed_rows = [];
                                foreach ( $raw_data as $raw_row ) {
                                     if ( ! is_array( $raw_row ) ) continue;
                                    $processed_row = [];
                                    foreach ( $raw_row as $field_key => $field_value ) {
                                         // Use the dynamically generated map passed via 'use'
                                        if ( isset( $current_key_to_name_map[$field_key] ) ) {
                                            $field_name = $current_key_to_name_map[$field_key];
                                            // Special Handling / Type Casting (keep this logic)
                                            if ( $field_name === 'gpt' ) {
                                                $processed_row[$field_name] = (bool) intval($field_value);
                                            } else {
                                                $processed_row[$field_name] = $field_value;
                                            }
                                        }
                                    }
                                    if (!empty($processed_row)) $processed_rows[] = $processed_row;
                                }
                                return $processed_rows;
                            } elseif (empty($raw_data)) {
                                 return [];
                            } else {
                                 return $raw_data; // Fallback
                            }
                        }
                        return null;
                    },

                    // --- UPDATE Callback ---
                    'update_callback' => function( $value, $object, $field_name_from_api ) use ( $acf_field_name, $current_name_to_key_map ) { // Pass name_to_key map
                        if ( function_exists( 'update_field' ) ) {

                            if ( ! is_array( $value ) ) {
                                 if ( is_null( $value ) || ( is_array( $value ) && empty( $value ) ) ) {
                                     $value_to_save = $value;
                                 } else {
                                     return new WP_Error( /* ... invalid data ... */ );
                                 }
                            } else {
                                $value_to_save = [];
                                foreach ($value as $row_with_names) {
                                     if (!is_array($row_with_names)) continue;
                                    $row_with_keys = [];
                                    foreach ($row_with_names as $field_name => $field_value) {
                                         // Use the dynamically generated map passed via 'use'
                                        if (isset($current_name_to_key_map[$field_name])) {
                                            $field_key = $current_name_to_key_map[$field_name];
                                             // Reverse Type Casting (keep this logic)
                                            if ($field_name === 'gpt' && is_bool($field_value)) {
                                                $row_with_keys[$field_key] = $field_value ? '1' : '0';
                                            } else {
                                                 $row_with_keys[$field_key] = $field_value;
                                            }
                                        }
                                    }
                                     if (!empty($row_with_keys)) $value_to_save[] = $row_with_keys;
                                }
                            }

                            $result = update_field( $acf_field_name, $value_to_save, $object->ID );
                            // ... error/result check ...
                            return true;
                        }
                        return new WP_Error( /* ... ACF missing ... */ );
                    },

                    // --- SCHEMA ---
                    'schema' => $schema, // Use the schema fetched/generated earlier

                    // --- PERMISSION Callback ---
                     'permission_callback' => function( $request ) { /* ... unchanged ... */ },
                ]
            );
             $this->log_message( "Registered REST field '{$api_field_name}' (for ACF '{$acf_field_name}') using dynamic maps for post types: " . implode( ', ', $target_post_types ) );

        } // End foreach $acf_fields_to_register

        // --- Register standard meta fields ---
        $this->register_standard_rest_meta( $target_post_types, $options );

    } // End register_custom_acf_rest_fields

    /**
     * Helper function to register standard WP meta fields (non-ACF repeaters)
     */
    private function register_standard_rest_meta( $target_post_types, $options ) {
        // --- Define standard meta keys ---
        $standard_meta_fields = [
            'finder_keys_meta' => 'array',
            // If finder_keys (textarea) is NOT handled by ACF PRO (or you prefer manual):
            'finder_keys' => 'string',
        ];

        $target_languages = isset($options['target_languages']) && is_array($options['target_languages'])
                            ? $options['target_languages']
                            : [];
        $dynamic_translation_meta_keys = [];
        foreach ($target_languages as $lang_code) {
            $dynamic_translation_meta_keys[$lang_code . '_messages_001'] = 'object'; // or 'array'
        }

        $all_meta_to_register = array_merge(
            $standard_meta_fields,
            $dynamic_translation_meta_keys
        );

        foreach ($target_post_types as $post_type) {
            $post_type_object = get_post_type_object( $post_type );
            if ( ! $post_type_object ) continue; // Skip if post type doesn't exist

            foreach ($all_meta_to_register as $meta_key => $meta_type) {
                register_post_meta( $post_type, $meta_key, [
                    'show_in_rest' => true,
                    'single'       => true,
                    'type'         => $meta_type,
                    'auth_callback' => function() use ( $post_type_object ) {
                        // Check edit capability for the post type
                        return current_user_can( $post_type_object->cap->edit_posts );
                    }
                    // Optional: Add sanitize_callback or specific schema if needed
                ]);
                $this->log_message("Registered WP meta key '{$meta_key}' for post type '{$post_type}' via register_post_meta.");
            }
        }
    }


    /**
     * Defines the REST API schema for the 'messages' ACF Repeater field.
     *
     * @return array The schema definition.
     */
    private function get_messages_repeater_schema() {
        return [
            'description' => __( 'ACF Repeater field containing AI commands and results.', 'gpt-auto-translate' ),
            'type'        => 'array',
            'context'     => [ 'view', 'edit' ],
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    // Use field names as keys now
                    'role' => [
                        'description' => __( 'Role for the AI message (e.g., user, system, assistant).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => true,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'gpt' => [
                        'description' => __( 'Indicates if GPT should process this command.', 'gpt-auto-translate' ),
                        'type'        => 'boolean', // We are casting to boolean in the get_callback
                        'required'    => false, // Based on ACF default
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'content' => [
                        'description' => __( 'The command or prompt text (textarea).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => true,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'meta_key' => [
                        'description' => __( 'Target meta key for the result (e.g., main_content).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => true,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'status' => [
                        'description' => __( 'Processing status of this command.', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => true,
                        'enum'        => ['pending', 'completed', 'failed'],
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'result' => [
                        'description' => __( 'The generated content (raw HTML from WYSIWYG).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is HTML string
                        'required'    => false,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'processed_date' => [
                        'description' => __( 'Timestamp when the command was processed.', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                         // Consider adding 'format': 'date-time' if it's consistently ISO 8601
                        'required'    => false,
                        'context'     => [ 'view', 'edit' ],
                        // 'readonly'   => true, // Could make it read-only if desired
                    ],
                ],
                 // Optionally add 'required' array listing mandatory field names for the object
                 // 'required' => ['role', 'content', 'meta_key', 'status'],
            ],
        ];
    }


    /**
     * Defines the REST API schema for the 'translation_pages' ACF Repeater field.
     *
     * @return array The schema definition.
     */
    private function get_translation_pages_repeater_schema() {
        return [
            'description' => __( 'ACF Repeater field storing links to translated versions.', 'gpt-auto-translate' ),
            'type'        => 'array',
            'context'     => [ 'view', 'edit' ],
            'items'       => [
                'type'       => 'object',
                'properties' => [
                     // Use field names as keys now
                    'language' => [
                        'description' => __( 'Target language code (alpha-2).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => false,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'title' => [
                        'description' => __( 'Title of the translated page.', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => false,
                        'context'     => [ 'view', 'edit' ],
                    ],
                    'slug' => [
                        'description' => __( 'Slug of the translated page (often points to original).', 'gpt-auto-translate' ),
                        'type'        => 'string', // Raw value is string
                        'required'    => false,
                        'context'     => [ 'view', 'edit' ],
                    ],
                ],
                // Optionally add 'required' array listing mandatory field names for the object
            ],
        ];
    }


    /**
     * Dynamically retrieves the key <=> name maps for an ACF repeater's sub-fields.
     *
     * @param string $repeater_field_name The name of the parent repeater field (e.g., 'messages').
     * @return array|null ['key_to_name' => [...], 'name_to_key' => [...]] or null on failure.
     */
    private function get_dynamic_acf_repeater_maps( $repeater_field_name ) {
        if ( ! function_exists( 'acf_get_field' ) ) {
             $this->log_message("Error: acf_get_field() function not found. Cannot build dynamic maps.");
             return null; // ACF Pro likely needed or core function unavailable
        }

        // Try getting the field object using the NAME.
        // Note: acf_get_field() is often preferred over get_field_object() for getting definitions.
        $repeater_field_object = acf_get_field( $repeater_field_name );

        if ( ! $repeater_field_object || empty( $repeater_field_object['sub_fields'] ) || ! is_array( $repeater_field_object['sub_fields'] ) ) {
            // Fallback: Sometimes the field object might be associated with a specific post ID if contexts vary.
            // This is less common for just getting the definition, but let's try finding *any* post
            // of a target type to potentially get the field object if the global lookup fails.
             $options           = get_option( 'gpt_auto_translate_options' );
             $target_post_types = isset( $options['target_post_types'] ) && is_array( $options['target_post_types'] ) ? $options['target_post_types'] : ['page']; // Default 'page' maybe?
             if (!empty($target_post_types)) {
                 $args = [
                    'post_type'      => $target_post_types[0], // Just check the first target type
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'post_status'    => 'any', // Find any post
                 ];
                 $posts = get_posts($args);
                 if (!empty($posts)) {
                    $repeater_field_object = get_field_object($repeater_field_name, $posts[0], ['load_value' => false]); // Don't need value
                 }
             }
        }


        // Final check after potential fallback
        if ( ! $repeater_field_object || empty( $repeater_field_object['sub_fields'] ) || ! is_array( $repeater_field_object['sub_fields'] ) ) {
            $this->log_message( "Error: Could not retrieve valid ACF field object or sub-fields for repeater '{$repeater_field_name}'." );
             return null;
        }

        $key_to_name_map = [];
        $name_to_key_map = [];

        foreach ( $repeater_field_object['sub_fields'] as $sub_field ) {
            if ( isset( $sub_field['key'], $sub_field['name'] ) ) {
                $key_to_name_map[ $sub_field['key'] ] = $sub_field['name'];
                $name_to_key_map[ $sub_field['name'] ] = $sub_field['key'];
            }
        }

        if ( empty( $key_to_name_map ) ) {
            $this->log_message("Warning: Generated empty key/name maps for repeater '{$repeater_field_name}'. Check ACF configuration.");
            return null;
        }

        return [
            'key_to_name' => $key_to_name_map,
            'name_to_key' => $name_to_key_map,
        ];
    }

}

/**
 * Enregistrement des hooks d'activation et de désactivation.
 * Doit être fait dans le scope global du fichier principal.
 */
register_activation_hook( __FILE__, [ 'Gpt_Auto_Translate', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Gpt_Auto_Translate', 'deactivate' ] );

/**
 * Fonction principale pour initialiser le plugin.
 * Lance la méthode get_instance() pour créer ou récupérer l'instance unique.
 *
 * @return Gpt_Auto_Translate L'instance principale du plugin.
 */
function gpt_auto_translate_load() {
    return Gpt_Auto_Translate::get_instance();
}

// Lance le plugin !
gpt_auto_translate_load();

?>