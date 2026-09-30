import {createContext, useContext} from 'react';

// True in the 9:16 (1080 × 1920) cut. Scenes read it to stack text above the visual.
export const VerticalContext = createContext(false);

export const useVertical = (): boolean => useContext(VerticalContext);
