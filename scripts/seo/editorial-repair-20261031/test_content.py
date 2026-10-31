#!/usr/bin/env python3
import unittest
from build_review import facts_for


def product(description, name='Krem do twarzy', source='seni24'):
    return {'id':999999, 'description':description, 'name':name, 'external_source':source}


class EvidenceGuards(unittest.TestCase):
    def test_related_seni_products_are_not_ingredients(self):
        text='Krem do twarzy. Masło shea - składnik. Dlaczego wybrać specjalistyczne produkty Seni Care? Krem ochronny z tlenkiem cynku.'
        facts=facts_for(product(text))
        self.assertTrue(any(f[0]=='Z masłem shea.' for f in facts))
        self.assertFalse(any('cynku' in f[0] for f in facts))

    def test_injection_device_does_not_inherit_filler_ingredient(self):
        self.assertFalse(any('kwasem hialuronowym' in f[0] for f in facts_for(product('Do podawania wypełniaczy na bazie kwasu hialuronowego.', 'Mikrokaniula'))))

    def test_packaging_material_is_not_whole_product_material(self):
        facts=facts_for(product('Gaza bawełniana w worek z folii polietylenowej.', 'Gaza'))
        self.assertFalse(any('Wykonanie z polietylenu' in f[0] for f in facts))

    def test_fabric_percentage_list_must_total_one_hundred(self):
        facts=facts_for(product('Skład: 50% bawełna, 80% poliester.', 'Bluza', 'eldan'))
        self.assertFalse(any(f[0].startswith('Skład:') for f in facts))

    def test_negated_ingredient_does_not_become_positive_fact(self):
        self.assertFalse(any('kwasem hialuronowym' in f[0] for f in facts_for(product('Nie zawiera kwasu hialuronowego.'))))

    def test_variant_options_never_provide_description_features(self):
        self.assertEqual([], facts_for(product('Opis Parametry produktu Kolor Biały Dostępne warianty Kolor Czarny.', 'Bluza', 'wojdak')))


if __name__=='__main__':
    unittest.main()
