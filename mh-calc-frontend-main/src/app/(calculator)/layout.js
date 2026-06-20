import css from './page.module.scss';   

export default function RootLayout({ children }) {
  return (
    <div className={css.wrapper}>  
      {children}
    </div>
  );
}; 
