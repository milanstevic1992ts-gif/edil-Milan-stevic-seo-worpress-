<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Schema {
    private EMS_Local_SEO_Compatibility $compatibility;

    public function __construct( EMS_Local_SEO_Compatibility $compatibility ) {
        $this->compatibility = $compatibility;
    }

    public function hooks(): void {
        add_action( 'wp_head', array( $this, 'output_graph' ), 30 );
        add_filter( 'the_seo_framework_schema_graph_data', array( $this, 'filter_tsf_graph' ), 20, 2 );
    }

    public function output_graph(): void {
        if ( is_admin() || is_feed() || ! $this->should_output() ) {
            return;
        }

        $graph = $this->build_graph();
        if ( empty( $graph ) ) {
            return;
        }

        $payload = array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values( $graph ),
        );

        echo '<script type="application/ld+json" class="ems-local-seo-schema">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    public function should_output(): bool {
        if ( ! EMS_Local_SEO_Settings::get( 'enable_schema', 1 ) ) {
            return false;
        }

        $ownership = (string) EMS_Local_SEO_Settings::get( 'schema_ownership', 'auto' );
        if ( 'off' === $ownership ) {
            return false;
        }
        if ( 'ems' === $ownership ) {
            return true;
        }

        if ( $this->compatibility->has_tsf() ) {
            return false;
        }

        return ! $this->compatibility->has_external_seo_plugin();
    }

    public function filter_tsf_graph( array $graph, mixed $args = null ): array {
        if ( ! EMS_Local_SEO_Settings::get( 'enable_schema', 1 ) ) {
            return $graph;
        }

        if ( 'auto' !== (string) EMS_Local_SEO_Settings::get( 'schema_ownership', 'auto' ) || ! $this->compatibility->has_tsf() ) {
            return $graph;
        }

        $organization_index = null;
        $website_index      = null;
        $webpage_index      = null;
        $organization_id    = untrailingslashit( home_url( '/' ) ) . '/#/schema/Organization';

        foreach ( $graph as $index => $entity ) {
            $types = (array) ( $entity['@type'] ?? array() );

            if ( in_array( 'Organization', $types, true ) || in_array( 'GeneralContractor', $types, true ) || in_array( 'HomeAndConstructionBusiness', $types, true ) || in_array( 'HousePainter', $types, true ) ) {
                $organization_index = $index;
                if ( ! empty( $entity['@id'] ) ) {
                    $organization_id = (string) $entity['@id'];
                }
            }

            if ( in_array( 'WebSite', $types, true ) ) {
                $website_index = $index;
            }

            if ( in_array( 'WebPage', $types, true ) ) {
                $webpage_index = $index;
            }
        }

        $business = $this->business_node( $organization_id );
        if ( ! empty( $business ) ) {
            $street = trim( (string) EMS_Local_SEO_Settings::get( 'street_address', '' ) );
            if ( '' === $street && 'Organization' !== (string) ( $business['@type'] ?? 'Organization' ) ) {
                $business['@type'] = 'Organization';
                unset( $business['address'], $business['geo'] );
            }

            if ( null !== $organization_index ) {
                $existing_same_as = (array) ( $graph[ $organization_index ]['sameAs'] ?? array() );
                $ems_same_as      = (array) ( $business['sameAs'] ?? array() );

                $graph[ $organization_index ] = array_replace(
                    $graph[ $organization_index ],
                    $business
                );

                if ( ! empty( $existing_same_as ) || ! empty( $ems_same_as ) ) {
                    $graph[ $organization_index ]['sameAs'] = array_values(
                        array_unique(
                            array_filter(
                                array_merge( $existing_same_as, $ems_same_as )
                            )
                        )
                    );
                }
            } else {
                $organization_index = count( $graph );
                $graph[]            = $business;
            }

            if ( null !== $website_index ) {
                $graph[ $website_index ]['publisher'] = array( '@id' => $organization_id );
            }
        }

        if ( ! is_singular() ) {
            return $graph;
        }

        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return $graph;
        }

        $schema_type = $this->resolve_schema_type( $post );
        $page_id     = null !== $webpage_index && ! empty( $graph[ $webpage_index ]['@id'] )
            ? (string) $graph[ $webpage_index ]['@id']
            : get_permalink( $post );

        if ( 'service' === $schema_type ) {
            $service_id = get_permalink( $post ) . '#service';
            $graph[]     = $this->service_node( $post, $service_id, $organization_id );

            if ( null !== $webpage_index ) {
                $graph[ $webpage_index ]['mainEntity'] = array( '@id' => $service_id );
            }
        } elseif ( 'article' === $schema_type && ! $this->graph_has_type( $graph, 'Article' ) ) {
            $article_id = get_permalink( $post ) . '#article';
            $graph[]     = $this->article_node( $post, $article_id, $organization_id, $page_id );

            if ( null !== $webpage_index ) {
                $graph[ $webpage_index ]['mainEntity'] = array( '@id' => $article_id );
            }
        }

        return $graph;
    }

    private function graph_has_type( array $graph, string $type ): bool {
        foreach ( $graph as $entity ) {
            if ( in_array( $type, (array) ( $entity['@type'] ?? array() ), true ) ) {
                return true;
            }
        }

        return false;
    }

    public function build_graph(): array {
        $site_url    = untrailingslashit( home_url( '/' ) );
        $business_id = $site_url . '/#localbusiness';
        $website_id  = $site_url . '/#website';
        $graph       = array();

        $graph['website'] = array(
            '@type'      => 'WebSite',
            '@id'        => $website_id,
            'url'        => home_url( '/' ),
            'name'       => get_bloginfo( 'name' ),
            'inLanguage' => get_bloginfo( 'language' ),
            'publisher'  => array( '@id' => $business_id ),
        );

        $business = $this->business_node( $business_id );
        if ( ! empty( $business ) ) {
            $graph['business'] = $business;
        }

        if ( is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post ) {
                $page_id = get_permalink( $post ) . '#webpage';
                $webpage = array(
                    '@type'        => 'WebPage',
                    '@id'          => $page_id,
                    'url'          => get_permalink( $post ),
                    'name'         => get_the_title( $post ),
                    'isPartOf'     => array( '@id' => $website_id ),
                    'inLanguage'   => get_bloginfo( 'language' ),
                    'dateModified' => get_post_modified_time( DATE_W3C, true, $post ),
                );

                $description = trim( (string) get_post_meta( $post->ID, '_ems_seo_description', true ) );
                if ( '' !== $description ) {
                    $webpage['description'] = $description;
                }

                $schema_type = $this->resolve_schema_type( $post );
                if ( 'service' === $schema_type ) {
                    $service_id            = get_permalink( $post ) . '#service';
                    $webpage['mainEntity'] = array( '@id' => $service_id );
                    $graph['service']       = $this->service_node( $post, $service_id, $business_id );
                } elseif ( 'article' === $schema_type ) {
                    $article_id            = get_permalink( $post ) . '#article';
                    $webpage['mainEntity'] = array( '@id' => $article_id );
                    $graph['article']       = $this->article_node( $post, $article_id, $business_id, $page_id );
                }

                $graph['webpage'] = $webpage;

                if ( EMS_Local_SEO_Settings::get( 'enable_breadcrumbs', 1 ) ) {
                    $breadcrumb = $this->breadcrumb_node( $post );
                    if ( ! empty( $breadcrumb ) ) {
                        $graph['breadcrumb'] = $breadcrumb;
                    }
                }
            }
        }

        return $graph;
    }

    private function business_node( string $business_id ): array {
        $name = trim( (string) EMS_Local_SEO_Settings::get( 'business_name', '' ) );
        if ( '' === $name ) {
            return array();
        }

        $schema_type  = (string) EMS_Local_SEO_Settings::get( 'business_schema_type', 'GeneralContractor' );
        $allowed_types = array( 'GeneralContractor', 'HomeAndConstructionBusiness', 'HousePainter', 'Organization' );
        if ( ! in_array( $schema_type, $allowed_types, true ) ) {
            $schema_type = 'GeneralContractor';
        }

        $node = array(
            '@type'       => $schema_type,
            '@id'         => $business_id,
            'name'        => $name,
            'url'         => (string) EMS_Local_SEO_Settings::get( 'website', home_url( '/' ) ),
            'description' => (string) EMS_Local_SEO_Settings::get( 'description', '' ),
        );

        $legal = trim( (string) EMS_Local_SEO_Settings::get( 'legal_name', '' ) );
        if ( '' !== $legal ) {
            $node['legalName'] = $legal;
        }

        foreach ( array( 'phone' => 'telephone', 'email' => 'email', 'logo_url' => 'logo' ) as $setting => $property ) {
            $value = trim( (string) EMS_Local_SEO_Settings::get( $setting, '' ) );
            if ( '' !== $value ) {
                $node[ $property ] = $value;
            }
        }

        $address = array_filter(
            array(
                '@type'          => 'PostalAddress',
                'streetAddress'  => (string) EMS_Local_SEO_Settings::get( 'street_address', '' ),
                'addressLocality'=> (string) EMS_Local_SEO_Settings::get( 'locality', '' ),
                'addressRegion'  => (string) EMS_Local_SEO_Settings::get( 'region', '' ),
                'postalCode'     => (string) EMS_Local_SEO_Settings::get( 'postal_code', '' ),
                'addressCountry' => (string) EMS_Local_SEO_Settings::get( 'country', 'IT' ),
            ),
            static fn( mixed $value ): bool => '' !== $value && null !== $value
        );
        if ( '' !== trim( (string) EMS_Local_SEO_Settings::get( 'street_address', '' ) ) && count( $address ) > 1 ) {
            $node['address'] = $address;
        }

        $lat = trim( (string) EMS_Local_SEO_Settings::get( 'latitude', '' ) );
        $lng = trim( (string) EMS_Local_SEO_Settings::get( 'longitude', '' ) );
        if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
            $node['geo'] = array(
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            );
        }

        $areas = $this->lines( (string) EMS_Local_SEO_Settings::get( 'service_areas', '' ) );
        if ( ! empty( $areas ) ) {
            $node['areaServed'] = array_map(
                static fn( string $area ): array => array( '@type' => 'AdministrativeArea', 'name' => $area ),
                $areas
            );
        }

        $services = $this->lines( (string) EMS_Local_SEO_Settings::get( 'services', '' ) );
        if ( ! empty( $services ) ) {
            $node['knowsAbout'] = $services;
        }

        $same_as = array_filter(
            array(
                EMS_Local_SEO_Settings::get( 'google_business_url', '' ),
                EMS_Local_SEO_Settings::get( 'facebook_url', '' ),
                EMS_Local_SEO_Settings::get( 'instagram_url', '' ),
                EMS_Local_SEO_Settings::get( 'tiktok_url', '' ),
            )
        );
        if ( ! empty( $same_as ) ) {
            $node['sameAs'] = array_values( $same_as );
        }

        return $node;
    }

    private function resolve_schema_type( WP_Post $post ): string {
        $selected = (string) get_post_meta( $post->ID, '_ems_seo_schema_type', true );
        if ( in_array( $selected, array( 'service', 'article', 'webpage', 'none' ), true ) ) {
            return $selected;
        }

        return 'post' === $post->post_type ? 'article' : 'webpage';
    }

    private function service_node( WP_Post $post, string $service_id, string $business_id ): array {
        $name = trim( (string) get_post_meta( $post->ID, '_ems_seo_service_name', true ) );
        if ( '' === $name ) {
            $name = get_the_title( $post );
        }

        $node = array(
            '@type'       => 'Service',
            '@id'         => $service_id,
            'name'        => $name,
            'serviceType' => $name,
            'url'         => get_permalink( $post ),
            'provider'    => array( '@id' => $business_id ),
        );

        $areas = $this->lines( (string) EMS_Local_SEO_Settings::get( 'service_areas', '' ) );
        if ( ! empty( $areas ) ) {
            $node['areaServed'] = array_map(
                static fn( string $area ): array => array( '@type' => 'AdministrativeArea', 'name' => $area ),
                $areas
            );
        }

        $description = trim( (string) get_post_meta( $post->ID, '_ems_seo_description', true ) );
        if ( '' !== $description ) {
            $node['description'] = $description;
        }

        return $node;
    }

    private function article_node( WP_Post $post, string $article_id, string $business_id, string $page_id ): array {
        $node = array(
            '@type'            => 'Article',
            '@id'              => $article_id,
            'headline'         => get_the_title( $post ),
            'url'              => get_permalink( $post ),
            'mainEntityOfPage' => array( '@id' => $page_id ),
            'datePublished'    => get_post_time( DATE_W3C, true, $post ),
            'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
            'publisher'        => array( '@id' => $business_id ),
            'inLanguage'       => get_bloginfo( 'language' ),
        );

        $image_id = get_post_thumbnail_id( $post );
        if ( $image_id ) {
            $image = wp_get_attachment_image_src( $image_id, 'full' );
            if ( is_array( $image ) ) {
                $node['image'] = array( $image[0] );
            }
        }

        return $node;
    }

    private function breadcrumb_node( WP_Post $post ): array {
        $items = array(
            array(
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => get_bloginfo( 'name' ),
                'item'     => home_url( '/' ),
            ),
        );

        $position = 2;
        if ( 'page' === $post->post_type ) {
            $ancestors = array_reverse( get_post_ancestors( $post ) );
            foreach ( $ancestors as $ancestor_id ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => get_the_title( $ancestor_id ),
                    'item'     => get_permalink( $ancestor_id ),
                );
            }
        } elseif ( 'post' === $post->post_type ) {
            $categories = get_the_category( $post->ID );
            if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                $category = $categories[0];
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => $category->name,
                    'item'     => get_category_link( $category ),
                );
            }
        }

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => get_the_title( $post ),
            'item'     => get_permalink( $post ),
        );

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => get_permalink( $post ) . '#breadcrumb',
            'itemListElement' => $items,
        );
    }

    private function lines( string $value ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $value );
        return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
    }
}
