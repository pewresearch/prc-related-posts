<?php
// This file is generated. Do not modify it manually.
return array(
	'related-posts-query' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-block/related-posts-query',
		'version' => '2.0.0',
		'title' => 'Related Posts Query',
		'category' => 'design',
		'description' => 'Display custom related posts defaulting to posts with the same primary taxonomy term.',
		'keywords' => array(
			'related posts'
		),
		'attributes' => array(
			'perPage' => array(
				'type' => 'number',
				'default' => 5
			),
			'queryId' => array(
				'type' => 'string',
				'default' => 'related-posts-0'
			),
			'allowedBlocks' => array(
				'type' => 'array'
			),
			'orientation' => array(
				'type' => 'string',
				'default' => 'vertical'
			),
			'style' => array(
				'type' => 'object',
				'default' => array(
					'spacing' => array(
						'blockGap' => 'var:preset|spacing|20'
					)
				)
			)
		),
		'supports' => array(
			'anchor' => true,
			'html' => false,
			'align' => array(
				'wide',
				'full',
				'left',
				'right',
				'center'
			),
			'spacing' => array(
				'blockGap' => true,
				'margin' => array(
					'top',
					'bottom'
				),
				'padding' => true,
				'__experimentalDefaultControls' => array(
					'padding' => true
				)
			),
			'typography' => array(
				'fontSize' => true,
				'__experimentalFontFamily' => true,
				'__experimentalDefaultControls' => array(
					'fontSize' => true,
					'__experimentalFontFamily' => true
				)
			)
		),
		'textdomain' => 'related-posts-query',
		'editorScript' => 'file:./index.js',
		'style' => 'file:./style-index.css'
	)
);
