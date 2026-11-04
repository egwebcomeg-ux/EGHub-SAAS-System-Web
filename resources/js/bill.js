// The money rule of a bill, the same as App\Services\Orders\Bill on the server (which prices every order in the end):
// the service charge is taken in the hall only, on the food after every discount; the tax on that food plus the
// service. `rates` is the page's { service, tax } in percent (12 for 12%).
export function charges(food, dineIn, rates) {
    const service = dineIn ? food * (Number(rates.service) || 0) / 100 : 0;
    const tax = (food + service) * (Number(rates.tax) || 0) / 100;
    return { service, tax };
}
