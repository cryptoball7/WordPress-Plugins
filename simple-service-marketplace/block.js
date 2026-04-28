const { registerBlockType } = wp.blocks;
const { createElement: el } = wp.element;

registerBlockType('sso/order-form', {
    title: 'Service Order Form',
    icon: 'forms',
    category: 'widgets',

    edit: () => {
        return el('p', {}, 'Order form will appear on frontend.');
    },

    save: () => null // dynamic block
});