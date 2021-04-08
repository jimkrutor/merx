<?php
namespace Wagnerwagner\Merx;

use Wagnerwagner\Merx\ProductList;
use Kirby\Exception\Exception;

class Cart extends ProductList
{
    protected $sessionName = 'ww.merx.cartItems';


    /**
     * @param array $data List of product items. Product items must contain `id`. `quantity`, `title`, `price`, `tax` are optional.
     */
    public function __construct(array $data = [])
    {
        $kirby = kirby();
        if (count($data) === 0 && is_array($kirby->session()->get($this->sessionName))) {
            $data = $kirby->session()->get($this->sessionName);
        }
        parent::__construct($data);
        Merx::triggerHook('ww.merx.cart', ['cart' => $this]);
        $this->save();
    }


    /**
     * Adds item to cart.
     *
     * @param mixed $args `($cartItem)` or `($key, $cartItem)`. $cartItem must contain a valid product page id.
     * @throws Exception error.merx.cart.add
     */
    public function add(...$args): self
    {
        try {
            if (count($args) === 1) {
                $cartItem = $args[0];
                if (!is_array($args[0])) {
                    throw new \Exception('First argument has to be an array');
                }
                if (!isset($cartItem['id'])) {
                    throw new \Exception('No "id" is provided.');
                }
                $page = page($cartItem['id']);
                if (!$page) {
                    throw new \Exception('Page not found.');
                } else if (!$page->price()->exists()) {
                    throw new \Exception('Page must have a price field.');
                }
                $this->append($cartItem);
            } else if (count($args) === 2) {
                $this->append($args[0], $args[1]);
            }

            $this->save();
            return $this;
        } catch (\Exception $ex) {
            throw new Exception([
                'key' => 'merx.cart.add',
                'data' => [
                    'id' => $cartItem['id'] ?? '',
                ],
                'details' => [
                    'exception' => $ex,
                ],
            ]);
        }
    }


    /**
     * Removes item from Cart by key
     *
     * @param mixed $key the name of the key
     */
    public function remove($key)
    {
        if (isset($this->data[$key])) {
            parent::remove($key);
            $this->save();
        }
    }



    /**
     * Updates existing items.
     *
     * @param array $cartItems List of cart items.
     */
    public function update(array $cartItems): parent
    {
        try {
            foreach ($cartItems as $cartItem) {
                parent::updateItem($cartItem);
            }
            $this->save();
            return $this;
        } catch (\Exception $ex) {
            throw new Exception([
                'key' => 'merx.cart.update',
                'details' => [
                    'exception' => $ex,
                ],
            ]);
        }
    }


    /**
     * Updates existing item.
     *
     * @param array $updatedItem Must contain a valid product page id.
     */
    public function updateItem(array $item): parent
    {
        parent::updateItem($item);
        $this->save();
        return $this;
    }

    /**
     * Get Stripe’s PaymentIntent.
     *
     * @param null|array $params Additional parameters used by \Stripe\PaymentIntent::create().
     * @param null|array|\Stripe\Util\RequestOptions $options Additional options used by \Stripe\PaymentIntent::create().
     *
     * @return \Stripe\PaymentIntent
     */
    public function getStripePaymentIntent(?array $params = [], $options = []): object
    {
        if ($this->getSum() === 0.0) {
            // set language for single language installations
            if (!option('languages', false) && option('locale', false)) {
                $locale = \Kirby\Toolkit\Locale::normalize(option('locale'));
                $lang = substr($locale[LC_ALL] ?? $locale[LC_MESSAGES], 0, 2);
                kirby()->setCurrentTranslation($lang);
                kirby()->setCurrentLanguage($lang);
            }

            throw new Exception([
                'key' => 'merx.emptycart',
                'httpCode' => 400,
            ]);
        }
        return Payment::createStripePaymentIntent($this->getSum(), $params, $options);
    }


    /**
     * Removes Cart from user’s session.
     */
    public function delete(): void
    {
        kirby()->session()->remove($this->sessionName);
        $this->data = [];
    }


    private function save(): self
    {
        kirby()->session()->set($this->sessionName, $this->toArray());
        return $this;
    }


    /**
     * Returns an array in the format of PayPal’s purchase_unit_request.
     *
     * @since 1.3.0
     */
    public function payPalPurchaseUnits(): array
    {
        $siteTitle = site()->title();
        $total = $this->getSum();
        $discount = 0;
        foreach ($this->values() as $cartItem) {
            if ($cartItem['price'] <= 0) {
                $discount += $cartItem['sum'];
            }
        }
        $discount = $discount * -1;
        $itemTotal = $total + $discount;
        $items = array_filter($this->values(), function ($cartItem) {
            return $cartItem['price'] > 0;
        });
        return [
            [
                "description" => (string)$siteTitle,
                "amount" => [
                    "value" => number_format($total, 2, '.', ''),
                    "currency_code" => option('ww.merx.currency'),
                    "breakdown" => [
                        "item_total" => [
                            "value" => number_format($itemTotal, 2, '.', ''),
                            "currency_code" => option('ww.merx.currency'),
                        ],
                        "discount" => [
                            "value" => number_format($discount, 2, '.', ''),
                            "currency_code" => option('ww.merx.currency'),
                       ],
                    ],
                ],
                "items" => array_map(function ($cartItem) {
                    return [
                        'name' => $cartItem['title'] ?? $cartItem['id'],
                        'unit_amount' => [
                            "value" => number_format($cartItem['price'], 2, '.', ''),
                            "currency_code" => option('ww.merx.currency'),
                        ],
                        'quantity' => $cartItem['quantity'],
                    ];
                }, $items),
            ],
        ];
    }
}
