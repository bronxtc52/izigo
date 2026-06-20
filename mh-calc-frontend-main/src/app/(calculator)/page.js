'use client';
import React, { useEffect } from 'react';
import CalculatorWrapper from '@/views/calculator/CalculatorWrapper';
import { useGlobalContext } from '@/common/GlobalContext';
import css from './page.module.scss';
import { usePathname } from 'next/navigation';
import { PRODUCTION } from '@/common/utils/utils';  

const Calculator = () => {
  const { changeTokenStructure } = useGlobalContext();
  const pathname = usePathname();

  // Google Analytics && Yandex.Metrika 
  useEffect(() => {
    if (PRODUCTION) {
      if (typeof window.gtag === 'function') {
        window.gtag('event', 'page_view', { page_path: pathname });
      };

      if (typeof window.ym === 'function') {
        window.ym(99044373, 'hit', pathname);
      };
    }
  }, [pathname, PRODUCTION]);

  useEffect(() => {
    if (typeof window !== 'undefined') {
      const tokenLink = new URLSearchParams(window.location.search).get('token');

      if (tokenLink) {
        changeTokenStructure(tokenLink);
      };
    };
  }, []);

  return (
    <div className={css.page}>
      <CalculatorWrapper />
    </div>
  );
};

export default Calculator;
