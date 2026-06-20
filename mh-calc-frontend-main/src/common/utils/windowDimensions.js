import { useState, useEffect } from "react";

export const getDimensions = () => {
    if (!window) return {};
    let width = window.innerWidth;
    let height = window.innerHeight;
    return { width, height };
};

export const eventDimension = (func, remove) => {
    if (!window) return null;
    return remove ? window.removeEventListener('resize', func) : window.addEventListener('resize', func);
}



export const useWindowDimensions = () => {
    const [windowDimensions, setWindowDimensions] = useState();

    useEffect(() => {
        function handleResize() {
            const { width } = getDimensions();
            setWindowDimensions(width);
        }
        handleResize();
        eventDimension(handleResize);
        return () => eventDimension(handleResize, true);
    }, []);

    return windowDimensions;
};